<?php
/** Persistent voucher delivery state, shared by the queue and manual sends. */
namespace Eva_Course_Bookings;

defined('ABSPATH') || exit;

class Gift_Delivery
{
    const HOOK = 'eva_send_gift_voucher';
    const GROUP = 'eva-course-gifts';
    const RECONCILE = 'eva_reconcile_gift_vouchers';
    const NOTE_MAX_LENGTH = 250;
    const NOTE_MAX_BREAKS = 2;

    public function __construct()
    {
        add_action('woocommerce_payment_complete', array($this, 'sync_order'));
        add_action('woocommerce_order_status_changed', array($this, 'sync_order'));
        add_action('woocommerce_order_refunded', array($this, 'sync_order'));
        add_action(self::HOOK, array($this, 'send'), 10, 2);
        add_action('action_scheduler_init', array($this, 'init_queue'));
        add_action(self::RECONCILE, array($this, 'reconcile'));
        add_action('woocommerce_before_delete_order', array($this, 'cancel_order_jobs'));
    }

    public static function enabled($product_id)
    {
        return Plugin::is_course_skip_slot_enabled((int) $product_id) && (bool) get_post_meta($product_id, '_eva_gift_pdf_id', true);
    }

    public static function min_delivery_date(\DateTimeImmutable $now)
    {
        return ($now < $now->setTime(8, 0, 0) ? $now : $now->modify('tomorrow'))->format('Y-m-d');
    }

    public static function due_at($date, $timezone)
    {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 08:00', new \DateTimeZone($timezone));
        if (! $time || $time->format('Y-m-d') !== $date) {
            throw new \RuntimeException('Data di invio non valida.');
        }
        return $time->getTimestamp();
    }

    public static function purchase_data($product_id, array $input)
    {
        $data = array();
        foreach (array('recipient', 'sender', 'note', 'mode', 'date') as $field) {
            $key = 'eva_gift_' . ('recipient' === $field ? 'recipient_name' : $field);
            if (isset($input[$key]) && ! is_string($input[$key])) {
                return new \WP_Error('gift', 'Dati regalo non validi.');
            }
            $value = wp_unslash($input[$key] ?? '');
            $data[$field] = 'note' === $field ? sanitize_textarea_field($value) : sanitize_text_field($value);
        }
        $data['timezone'] = wp_timezone_string();
        return self::validate_data($product_id, $data);
    }

    public static function validate_note($value)
    {
        if (! is_string($value)) {
            throw new \RuntimeException('Messaggio regalo non valido.');
        }
        $value = str_replace(array("\r\n", "\r"), "\n", sanitize_textarea_field($value));
        $lines = array_filter(array_map('trim', explode("\n", $value)), static function ($line) { return '' !== $line; });
        $value = implode("\n", $lines);
        if (mb_strlen($value) > self::NOTE_MAX_LENGTH || substr_count($value, "\n") > self::NOTE_MAX_BREAKS) {
            throw new \RuntimeException('La dedica può contenere al massimo ' . self::NOTE_MAX_LENGTH . ' caratteri e ' . self::NOTE_MAX_BREAKS . ' a capo manuali.');
        }
        return $value;
    }

    public static function validate_data($product_id, array $data)
    {
        try {
            foreach (array('recipient', 'sender', 'note', 'mode', 'date', 'timezone') as $field) {
                if (! isset($data[$field]) || ! is_string($data[$field])) {
                    throw new \RuntimeException('Dati regalo incompleti.');
                }
            }
            if ('' === trim($data['recipient']) || '' === trim($data['sender']) || mb_strlen($data['recipient']) > 100 || mb_strlen($data['sender']) > 100) {
                throw new \RuntimeException('Inserisci i nomi del destinatario e del mittente (massimo 100 caratteri).');
            }
            $data['note'] = self::validate_note($data['note']);
            if (! in_array($data['mode'], array('now', 'scheduled'), true)) {
                throw new \RuntimeException('Scegli quando inviare il regalo.');
            }
            $timezone = new \DateTimeZone($data['timezone']);
            if ('scheduled' === $data['mode']) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data['date']) || $data['date'] < self::min_delivery_date(new \DateTimeImmutable('now', $timezone))) {
                    throw new \RuntimeException('Scegli una data con invio alle 08:00 ancora nel futuro, oppure seleziona l’invio subito dopo il pagamento.');
                }
                self::due_at($data['date'], $data['timezone']);
            } else {
                $data['date'] = '';
            }
            Voucher_Pdf::render($product_id, array_merge($data, array('reference' => '999999999999', 'quantity' => 999)));
            return $data;
        } catch (\Throwable $error) {
            return new \WP_Error('gift', 'Voucher regalo: ' . $error->getMessage());
        }
    }

    public static function remaining($order, $item)
    {
        return max(0, (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item($item->get_id()));
    }

    private static function eligible($order, $item)
    {
        return $order->is_paid() && self::remaining($order, $item) > 0;
    }

    public function init_queue()
    {
        if (! as_has_scheduled_action(self::RECONCILE, array(), self::GROUP)) {
            as_schedule_recurring_action(time() + 60, 300, self::RECONCILE, array(), self::GROUP, true);
        }
    }

    public function sync_order($order_id)
    {
        $order = wc_get_order($order_id);
        if (! $order || ! function_exists('as_schedule_single_action')) {
            return;
        }
        foreach ($order->get_items() as $item) {
            $item->read_meta_data(true);
            $status = $item->get_meta('_eva_gift_status');
            if (! $status || in_array($status, array('sent', 'sending'), true)) {
                continue;
            }
            $args = array($order->get_id(), $item->get_id());
            if (! self::eligible($order, $item)) {
                $this->unschedule($order->get_id(), $item->get_id());
                if ($order->has_status(array('cancelled', 'failed', 'refunded')) || 0 === self::remaining($order, $item)) {
                    $item->update_meta_data('_eva_gift_status', 'cancelled');
                } elseif (! in_array($status, array('failed', 'review'), true)) {
                    $item->update_meta_data('_eva_gift_status', 'awaiting_payment');
                }
                $item->save_meta_data();
                continue;
            }
            if (in_array($status, array('failed', 'review'), true)) {
                continue;
            }
            $item->update_meta_data('_eva_gift_status', 'scheduled');
            $item->save_meta_data();
            if (! as_has_scheduled_action(self::HOOK, $args, self::GROUP)) {
                $action = as_schedule_single_action(max(time(), (int) $item->get_meta('_eva_gift_due')), self::HOOK, $args, self::GROUP, true);
                if (! $action) {
                    $this->failure($order, $item, 'Impossibile creare il lavoro nella coda di invio.', 'queue');
                }
            }
        }
    }

    public function unschedule($order_id, $item_id)
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, array((int) $order_id, (int) $item_id), self::GROUP);
        }
    }

    public function cancel_order_jobs($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order) {
            foreach ($order->get_items() as $item) {
                $this->unschedule($order_id, $item->get_id());
            }
        }
    }

    public function reconcile()
    {
        global $wpdb;
        $last_id = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT oi.order_id, oi.order_item_id FROM {$wpdb->prefix}woocommerce_order_items oi
                 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
                 WHERE m.meta_key = '_eva_gift_status' AND m.meta_value IN ('scheduled','awaiting_payment','sending')
                 AND oi.order_item_id > %d ORDER BY oi.order_item_id LIMIT 100", $last_id
            ));
            foreach ($rows as $row) {
                $last_id = (int) $row->order_item_id;
                $order = wc_get_order($row->order_id);
                $item = $order ? $order->get_item($last_id) : false;
                if (! $item) {
                    continue;
                }
                if ('sending' === $item->get_meta('_eva_gift_status')) {
                    if ((int) $item->get_meta('_eva_gift_last_attempt') < time() - 15 * MINUTE_IN_SECONDS && update_metadata('order_item', $last_id, '_eva_gift_status', 'review', 'sending')) {
                        $item->read_meta_data(true);
                        $this->failure($order, $item, 'Invio interrotto: esito incerto. Verifica la posta inviata prima di riprovare.', 'recovery', true);
                    }
                } else {
                    $this->sync_order($order->get_id());
                }
            }
        } while (100 === count($rows));
    }

    public function send($order_id, $item_id, $manual = false)
    {
        $order = wc_get_order($order_id);
        $item = $order ? $order->get_item($item_id) : false;
        if (! $item) {
            return new \WP_Error('gift', 'Acquisto non trovato.');
        }
        $item->read_meta_data(true);
        $status = $item->get_meta('_eva_gift_status');
        if (! in_array($status, $manual ? array('scheduled', 'failed', 'review') : array('scheduled'), true)) {
            return new \WP_Error('gift', 'Questo voucher non può essere inviato nello stato attuale.');
        }
        if (! self::eligible($order, $item)) {
            $this->sync_order($order_id);
            return new \WP_Error('gift', 'Ordine non pagato, annullato o corso rimborsato.');
        }
        if (! $manual && (int) $item->get_meta('_eva_gift_due') > time()) {
            return new \WP_Error('gift', 'Invio non ancora previsto.');
        }
        if (! update_metadata('order_item', $item_id, '_eva_gift_status', 'sending', $status)) {
            return new \WP_Error('gift', 'Invio già in corso.');
        }
        $item->read_meta_data(true);
        $item->update_meta_data('_eva_gift_last_attempt', time());
        $item->save_meta_data();
        $path = null;
        $accepted = false;
        $mail_error = '';
        $capture_error = static function ($error) use (&$mail_error) { $mail_error = $error->get_error_message(); };
        add_action('wp_mail_failed', $capture_error);
        try {
            $data = $item->get_meta('_eva_gift_data');
            $recipient = $item->get_meta('_eva_gift_email');
            if (! is_array($data) || ! is_email($recipient)) {
                throw new \RuntimeException('Dati regalo o email destinatario non validi.');
            }
            $bytes = Voucher_Pdf::render($item->get_product_id(), array_merge($data, array('reference' => $order->get_id(), 'quantity' => self::remaining($order, $item))));
            $directory = realpath(sys_get_temp_dir());
            $webroot = realpath(ABSPATH);
            if (! $directory || ! $webroot || $directory === $webroot || strpos($directory, $webroot . DIRECTORY_SEPARATOR) === 0) {
                throw new \RuntimeException('Configura una directory temporanea privata per i voucher.');
            }
            $path = tempnam($directory, 'eva-voucher-');
            if ($path) {
                if (! rename($path, $path . '.pdf')) {
                    unlink($path);
                    throw new \RuntimeException('Impossibile creare l’allegato PDF.');
                }
                $path .= '.pdf';
            }
            if (! $path || ! chmod($path, 0600) || strlen($bytes) !== file_put_contents($path, $bytes)) {
                throw new \RuntimeException('Impossibile creare il voucher temporaneo.');
            }
            $emails = WC()->mailer()->get_emails();
            $email = $emails['WC_Email_Course_Gift'] ?? null;
            if (! $email || ! $email->trigger($order, $item, $path)) {
                throw new \RuntimeException($mail_error ?: 'Il sistema email ha rifiutato l’invio. Verifica trasporto e abilitazione dell’email Buono regalo corso.');
            }
            $accepted = true;
            $item->update_meta_data('_eva_gift_sent_at', time());
            $item->update_meta_data('_eva_gift_status', 'sent');
            $item->delete_meta_data('_eva_gift_error');
            $this->history($item, $manual ? 'manual' : 'cron', 'sent', '');
            $item->save_meta_data();
            $item->read_meta_data(true);
            if ('sent' !== $item->get_meta('_eva_gift_status') || ! $item->get_meta('_eva_gift_sent_at')) {
                throw new \RuntimeException('Email accettata, ma salvataggio dello storico non riuscito. Verifica la posta inviata prima di riprovare.');
            }
            $this->unschedule($order_id, $item_id);
            return true;
        } catch (\Throwable $error) {
            remove_action('wp_mail_failed', $capture_error);
            $this->failure($order, $item, $error->getMessage(), $manual ? 'manual' : 'cron', $accepted);
            return new \WP_Error('gift', $error->getMessage());
        } finally {
            remove_action('wp_mail_failed', $capture_error);
            if ($path && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function history($item, $source, $status, $error)
    {
        $history = $item->get_meta('_eva_gift_history');
        $history = is_array($history) ? $history : array();
        $history[] = array('at' => time(), 'source' => $source, 'user' => get_current_user_id(), 'status' => $status, 'error' => $error);
        $item->update_meta_data('_eva_gift_history', $history);
    }

    private function failure($order, $item, $message, $source, $uncertain = false)
    {
        $status = $uncertain ? 'review' : 'failed';
        $item->update_meta_data('_eva_gift_status', $status);
        $item->update_meta_data('_eva_gift_error', sanitize_text_field($message));
        $this->history($item, $source, $status, sanitize_text_field($message));
        $item->save_meta_data();
        $this->unschedule($order->get_id(), $item->get_id());
        $settings = Voucher_Pdf::settings();
        $body = sprintf("Ordine #%s, corso: %s\nDestinatario: %s\nErrore: %s\nGestione: %s", $order->get_order_number(), $item->get_name(), $item->get_meta('_eva_gift_email'), $message, admin_url('admin.php?page=eva-course-bookings&tab=invii_regalo'));
        try {
            if (! wc_mail($settings['error_email'], 'Errore invio buono regalo – ordine #' . $order->get_order_number(), nl2br(esc_html($body)))) {
                wc_get_logger()->error('Notifica errore voucher non inviata, ordine ' . $order->get_id(), array('source' => self::GROUP));
            }
        } catch (\Throwable $error) {
            wc_get_logger()->error('Notifica errore voucher: ' . $error->getMessage(), array('source' => self::GROUP));
        }
    }
}
