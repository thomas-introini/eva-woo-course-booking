<?php
/** Voucher templates, layout preview, and delivery history. */
namespace Eva_Course_Bookings;

defined('ABSPATH') || exit;

class Gift_Admin
{
    private $delivery;

    public function __construct(Gift_Delivery $delivery)
    {
        $this->delivery = $delivery;
        add_action('add_meta_boxes_product', array($this, 'product_box'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_eva_voucher_settings', array($this, 'save_settings'));
        add_action('admin_post_eva_voucher_product', array($this, 'save_course'));
        add_action('wp_ajax_eva_voucher_dimensions', array($this, 'dimensions'));
        add_action('admin_post_eva_voucher_preview', array($this, 'preview'));
        add_action('admin_post_eva_voucher_send', array($this, 'send'));
    }

    public function assets($hook)
    {
        if (strpos($hook, 'eva-course-bookings') !== false || ('product' === get_post_type() && in_array($hook, array('post.php', 'post-new.php'), true))) {
            wp_enqueue_media();
            wp_enqueue_script('eva-gift-note', EVA_COURSE_BOOKINGS_PLUGIN_URL . 'assets/js/gift-note.js', array(), EVA_COURSE_BOOKINGS_VERSION, true);
            wp_enqueue_script('eva-gift-admin', EVA_COURSE_BOOKINGS_PLUGIN_URL . 'assets/js/gift-admin.js', array('jquery', 'eva-gift-note'), EVA_COURSE_BOOKINGS_VERSION, true);
        }
    }

    public function product_box()
    {
        add_meta_box('eva-gift-voucher', 'Buono regalo PDF', array($this, 'product_field'), 'product', 'normal');
    }

    public function product_field($post)
    {
        $this->render_editor($post->ID);
        echo '<p>Premi <strong>Aggiorna</strong> per salvare il PDF e il layout di questo prodotto.</p>';
    }

    private function authorize_product($product_id)
    {
        if ('product' !== get_post_type($product_id) || ! current_user_can('manage_woocommerce') || ! current_user_can('edit_post', $product_id)) {
            wp_die('Corso non valido o permesso negato.', '', array('response' => 403));
        }
    }

    private function save_configuration($product_id)
    {
        $this->authorize_product($product_id);
        if (! isset($_POST['_eva_gift_pdf_id']) || ! is_scalar($_POST['_eva_gift_pdf_id'])) {
            return new \WP_Error('pdf', 'Seleziona un PDF dalla libreria media.');
        }
        $id = absint($_POST['_eva_gift_pdf_id']);
        if ($id && ('attachment' !== get_post_type($id) || 'application/pdf' !== get_post_mime_type($id))) {
            return new \WP_Error('pdf', 'Modello voucher non salvato: seleziona un PDF dalla libreria media.');
        }
        update_post_meta($product_id, '_eva_gift_pdf_id', $id);
        if (! $id) {
            return true;
        }
        try {
            $fields = isset($_POST['voucher']) ? wp_unslash($_POST['voucher']['fields'] ?? array()) : Voucher_Pdf::layout($product_id);
            Voucher_Pdf::render($product_id, Voucher_Pdf::sample(), $fields);
            update_post_meta($product_id, Voucher_Pdf::LAYOUT_META, $fields);
            return true;
        } catch (\Throwable $error) {
            return new \WP_Error('layout', 'PDF associato al corso, ma il buono non è ancora pronto all’invio. Correggi il layout del corso; il layout precedente è stato conservato: ' . $error->getMessage());
        }
    }

    public function save_product($product_id)
    {
        if (! isset($_POST['_eva_gift_pdf_id']) || ! current_user_can('manage_woocommerce') ||
            ! wp_verify_nonce(is_string($_POST['eva_voucher_nonce'] ?? null) ? $_POST['eva_voucher_nonce'] : '', 'eva_voucher_settings')) {
            return;
        }
        $result = $this->save_configuration($product_id);
        if (is_wp_error($result)) {
            \WC_Admin_Meta_Boxes::add_error($result->get_error_message());
        }
    }

    private function authorize($nonce, $field = '_wpnonce')
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Permesso negato.', '', array('response' => 403));
        }
        check_admin_referer($nonce, $field);
    }

    private function notice($message, $error, $tab, $product_id = 0)
    {
        set_transient('eva_gift_notice_' . get_current_user_id(), array('message' => $message, 'error' => $error), 60);
        wp_safe_redirect(add_query_arg('voucher_product_id', $product_id, admin_url('admin.php?page=eva-course-bookings&tab=' . $tab)));
        exit;
    }

    private function show_notice()
    {
        $key = 'eva_gift_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if ($notice) {
            delete_transient($key);
            echo '<div class="notice ' . ($notice['error'] ? 'notice-error' : 'notice-success') . '"><p>' . esc_html($notice['message']) . '</p></div>';
        }
    }

    public function save_settings()
    {
        $this->authorize('eva_voucher_settings');
        $email = wp_unslash($_POST['voucher']['error_email'] ?? '');
        if (! is_string($email) || ! is_email($email)) {
            $this->notice('Inserisci un indirizzo valido per le notifiche di errore.', true, 'impostazioni');
        }
        $settings = Voucher_Pdf::settings();
        $settings['error_email'] = sanitize_email($email);
        update_option(Voucher_Pdf::OPTION, $settings, false);
        $this->notice('Email notifiche errori salvata.', false, 'impostazioni');
    }

    public function save_course()
    {
        $this->authorize('eva_voucher_settings', 'eva_voucher_nonce');
        $product_id = absint($_POST['voucher_product_id'] ?? 0);
        $result = $this->save_configuration($product_id);
        $this->notice(is_wp_error($result) ? $result->get_error_message() : 'Configurazione del corso salvata. Le modifiche si applicano ai regali non ancora inviati.', is_wp_error($result), 'impostazioni', $product_id);
    }

    public function dimensions()
    {
        $this->authorize('eva_voucher_settings', 'eva_voucher_nonce');
        $this->authorize_product(absint($_POST['voucher_product_id'] ?? 0));
        try {
            wp_send_json_success(Voucher_Pdf::dimensions(absint($_POST['_eva_gift_pdf_id'] ?? 0)));
        } catch (\Throwable $error) {
            wp_send_json_error($error->getMessage(), 400);
        }
    }

    public function preview()
    {
        $this->authorize('eva_voucher_settings', isset($_POST['eva_voucher_nonce']) ? 'eva_voucher_nonce' : '_wpnonce');
        try {
            $data = Voucher_Pdf::sample();
            if (! empty($_POST['item_id'])) {
                $order = wc_get_order(absint($_POST['order_id'] ?? 0));
                $item = $order ? $order->get_item(absint($_POST['item_id'])) : false;
                if (! $item || ! $item->get_meta('_eva_gift_status')) {
                    throw new \RuntimeException('Regalo non trovato.');
                }
                $product_id = $item->get_product_id();
                $data = array_merge((array) $item->get_meta('_eva_gift_data'), array('reference' => $order->get_id(), 'quantity' => Gift_Delivery::remaining($order, $item)));
            } else {
                $product_id = absint($_POST['voucher_product_id'] ?? 0);
                foreach (array('recipient', 'sender', 'note') as $key) {
                    $value = $_POST['sample'][$key] ?? $data[$key];
                    if (! is_string($value) || ('note' !== $key && mb_strlen($value) > 100)) {
                        throw new \RuntimeException('Testo anteprima non valido o troppo lungo.');
                    }
                    $data[$key] = 'note' === $key ? Gift_Delivery::validate_note(wp_unslash($value)) : sanitize_textarea_field(wp_unslash($value));
                }
            }
            $this->authorize_product($product_id);
            $fields = isset($_POST['voucher']) ? wp_unslash($_POST['voucher']['fields'] ?? array()) : null;
            $pdf_id = isset($_POST['_eva_gift_pdf_id']) ? absint($_POST['_eva_gift_pdf_id']) : null;
            $bytes = Voucher_Pdf::render($product_id, $data, $fields, $pdf_id);
            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="anteprima-voucher.pdf"');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . strlen($bytes));
            echo $bytes;
            exit;
        } catch (\Throwable $error) {
            wp_die(esc_html($error->getMessage()), 'Anteprima voucher', array('response' => 400));
        }
    }

    public function send()
    {
        $item_id = absint($_POST['item_id'] ?? 0);
        $this->authorize('eva_send_gift_' . $item_id);
        $result = $this->delivery->send(absint($_POST['order_id'] ?? 0), $item_id, true);
        $this->notice(is_wp_error($result) ? $result->get_error_message() : 'Buono regalo accettato dal sistema email.', is_wp_error($result), 'invii_regalo');
    }

    public static function statuses()
    {
        return array('awaiting_payment' => 'In attesa di pagamento', 'scheduled' => 'Programmato', 'sending' => 'Invio in corso', 'sent' => 'Inviato', 'failed' => 'Errore', 'review' => 'Da verificare', 'cancelled' => 'Annullato');
    }

    private function courses()
    {
        return get_posts(array('post_type' => 'product', 'post_status' => array('publish', 'private', 'draft'), 'numberposts' => -1, 'meta_key' => '_eva_course_enabled', 'meta_value' => 'yes', 'orderby' => 'title', 'order' => 'ASC'));
    }

    private static function time($timestamp)
    {
        return $timestamp ? wp_date('d/m/Y H:i:s', (int) $timestamp) : '—';
    }

    public function render_settings()
    {
        if (! current_user_can('manage_woocommerce')) { return; }
        $this->show_notice();
        $settings = Voucher_Pdf::settings();
        $product_id = absint($_GET['voucher_product_id'] ?? 0);
        $courses = $this->courses();
        $available = array_filter($courses, static function ($course) { return current_user_can('edit_post', $course->ID); });
        if (! in_array($product_id, array_map('intval', wp_list_pluck($available, 'ID')), true)) { $product_id = 0; }
        ?>
        <hr><h2>Buoni regalo PDF</h2>
        <p>Ogni corso ha il proprio PDF e il proprio layout. Le dimensioni della pagina vengono lette dal PDF. Coordinate in millimetri dall’angolo superiore sinistro.</p>
        <p>Invio alle 08:00 nel fuso del negozio: <strong><?php echo esc_html(wp_timezone_string()); ?></strong>. Per puntualità, configura cron sul server ogni minuto. “Inviato” indica accettazione da parte del sistema email, non consegna nella casella.</p>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <?php wp_nonce_field('eva_voucher_settings'); ?>
            <input type="hidden" name="action" value="eva_voucher_settings">
            <p><label>Email notifiche errori <input type="email" required name="voucher[error_email]" value="<?php echo esc_attr($settings['error_email']); ?>"></label>
            <button class="button" type="submit">Salva email notifiche</button></p>
        </form>
        <form method="get" id="eva-voucher-course-selector">
            <input type="hidden" name="page" value="eva-course-bookings"><input type="hidden" name="tab" value="impostazioni">
            <p><label>Corso <select name="voucher_product_id"><option value="">Scegli un corso</option>
                <?php foreach ($available as $course) : ?>
                    <option value="<?php echo esc_attr($course->ID); ?>" <?php selected($product_id, $course->ID); ?>><?php echo esc_html($course->post_title); ?></option>
                <?php endforeach; ?>
            </select></label> <button type="submit" class="button">Carica corso</button></p>
        </form>
        <p class="description">Sono elencati anche i corsi senza PDF. Se un corso non compare, abilita “Abilita prenotazione corso” nella scheda prodotto.</p>
        <?php if ($product_id) : ?>
            <h3><?php echo esc_html(get_the_title($product_id)); ?></h3>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" id="eva-voucher-course-form">
                <input type="hidden" name="action" value="eva_voucher_product">
                <?php $this->render_editor($product_id); ?>
                <p><button class="button button-primary" type="submit">Salva configurazione del corso</button></p>
            </form>
        <?php endif;
    }

    private function render_editor($product_id)
    {
        $id = absint(get_post_meta($product_id, '_eva_gift_pdf_id', true));
        $fields = Voucher_Pdf::layout($product_id);
        $labels = array('recipient' => 'Destinatario', 'sender' => 'Mittente', 'note' => 'Messaggio', 'reference' => 'Riferimento prenotazione', 'quantity' => 'Partecipanti');
        $dimensions = 'Seleziona un PDF per rilevare le dimensioni.';
        if ($id) {
            try {
                $size = Voucher_Pdf::dimensions($id);
                $dimensions = sprintf('%.1f × %.1f mm', $size['width'], $size['height']);
            } catch (\Throwable $error) { $dimensions = $error->getMessage(); }
        }
        ?>
        <div class="eva-voucher-editor" data-post-url="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <?php wp_nonce_field('eva_voucher_settings', 'eva_voucher_nonce', false); ?>
            <input type="hidden" name="voucher_product_id" value="<?php echo esc_attr($product_id); ?>">
            <p><strong>Modello buono regalo PDF</strong>
                <input type="hidden" name="_eva_gift_pdf_id" id="eva-gift-pdf-id" value="<?php echo esc_attr($id); ?>">
                <span id="eva-gift-pdf-name"><?php echo esc_html($id ? get_the_title($id) : 'Nessun modello'); ?></span>
                <button type="button" class="button" id="eva-gift-pdf-select">Scegli PDF</button>
                <button type="button" class="button" id="eva-gift-pdf-clear">Rimuovi</button>
            </p>
            <p>Dimensioni pagina: <strong class="eva-voucher-dimensions" role="status"><?php echo esc_html($dimensions); ?></strong></p>
            <p class="description">PDF di una pagina, non protetto e senza campi modulo, preferibilmente PDF 1.4. Un PDF abilita gli invii regalo nei corsi che consentono l’acquisto senza data. Ogni campo deve rientrare nella pagina; le coordinate partono dall’angolo superiore sinistro.</p>
            <p>Seleziona nella colonna <strong>Stampa</strong> i testi da aggiungere al PDF per questo corso. I testi già presenti nel modello PDF rimangono visibili.</p>
            <div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Campo</th><th>Stampa</th><th>X</th><th>Y</th><th>Larghezza</th><th>Altezza</th><th>Carattere (pt)</th><th>Allineamento</th></tr></thead><tbody>
                <?php foreach ($labels as $name => $label) : $field = $fields[$name] ?? Voucher_Pdf::defaults()['fields'][$name]; ?>
                    <tr><th scope="row"><?php echo esc_html($label); ?></th>
                    <td><input type="hidden" name="voucher[fields][<?php echo esc_attr($name); ?>][enabled]" value="0">
                        <input type="checkbox" class="eva-voucher-field-enabled" aria-label="<?php echo esc_attr('Stampa ' . $label); ?>" name="voucher[fields][<?php echo esc_attr($name); ?>][enabled]" value="1" <?php checked($field['enabled'] ?? true); ?>></td>
                    <?php foreach (array('x', 'y', 'width', 'height', 'size') as $key) : ?>
                        <td><input aria-label="<?php echo esc_attr($label . ' ' . $key); ?>" type="number" <?php wp_readonly(! ($field['enabled'] ?? true)); ?> required step="any" min="<?php echo 'size' === $key ? '9' : '0'; ?>" <?php if ('size' === $key) { echo 'max="48"'; } ?> name="voucher[fields][<?php echo esc_attr($name); ?>][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($field[$key]); ?>" style="width:80px"></td>
                    <?php endforeach; ?>
                    <td><select aria-label="<?php echo esc_attr($label . ' allineamento'); ?>" name="voucher[fields][<?php echo esc_attr($name); ?>][align]">
                        <?php foreach (array('L' => 'Sinistra', 'C' => 'Centro', 'R' => 'Destra') as $value => $text) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($field['align'], $value); ?>><?php echo esc_html($text); ?></option><?php endforeach; ?>
                    </select></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <h3>Anteprima con il layout attuale, anche prima del salvataggio</h3>
            <?php foreach (array('recipient' => 'Destinatario', 'sender' => 'Mittente') as $key => $label) : ?>
                <p><label><?php echo esc_html($label); ?> <input type="text" maxlength="100" name="sample[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr(Voucher_Pdf::sample()[$key]); ?>"></label></p>
            <?php endforeach; ?>
            <p><label>Messaggio<br><textarea class="eva-gift-note" name="sample[note]" maxlength="<?php echo esc_attr(Gift_Delivery::NOTE_MAX_LENGTH); ?>" data-max-breaks="<?php echo esc_attr(Gift_Delivery::NOTE_MAX_BREAKS); ?>" aria-describedby="eva-voucher-note-help" rows="4" style="width:100%;max-width:700px"><?php echo esc_textarea(Voucher_Pdf::sample()['note']); ?></textarea></label></p>
            <p id="eva-voucher-note-help">Massimo <?php echo esc_html(Gift_Delivery::NOTE_MAX_LENGTH); ?> caratteri, spazi inclusi, e <?php echo esc_html(Gift_Delivery::NOTE_MAX_BREAKS); ?> a capo manuali. Le righe vuote vengono eliminate.</p>
            <p><button class="button eva-voucher-preview" type="button">Genera anteprima PDF</button></p>
            <iframe name="eva-voucher-preview-<?php echo esc_attr($product_id); ?>" title="Anteprima buono regalo" style="width:100%;height:650px;border:1px solid #ccc" src="about:blank"></iframe>
        </div>
        <?php
    }

    public function render_deliveries()
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        global $wpdb;
        $this->show_notice();
        $status = isset($_GET['gift_status']) && is_string($_GET['gift_status']) ? sanitize_key($_GET['gift_status']) : '';
        $product = absint($_GET['gift_product'] ?? 0);
        $period = isset($_GET['gift_period']) && is_string($_GET['gift_period']) ? sanitize_key($_GET['gift_period']) : '';
        $page = max(1, absint($_GET['paged'] ?? 1));
        $where = "m.meta_key = '_eva_gift_status'";
        if (isset(self::statuses()[$status])) {
            $where .= $wpdb->prepare(' AND m.meta_value = %s', $status);
        }
        if ($product) {
            $where .= $wpdb->prepare(" AND p.meta_value = %d", $product);
        }
        if (in_array($period, array('past', 'future'), true)) {
            $where .= $wpdb->prepare(' AND CAST(d.meta_value AS UNSIGNED) ' . ('past' === $period ? '<=' : '>') . ' %d', time());
        }
        $from = "FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta d ON d.order_item_id = oi.order_item_id AND d.meta_key = '_eva_gift_due'
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta p ON p.order_item_id = oi.order_item_id AND p.meta_key = '_product_id' WHERE {$where}";
        $total = (int) $wpdb->get_var("SELECT COUNT(*) {$from}");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT oi.order_id, oi.order_item_id {$from} ORDER BY CAST(d.meta_value AS UNSIGNED) DESC, oi.order_item_id DESC LIMIT 25 OFFSET %d", ($page - 1) * 25));
        ?>
        <h2>Invii regalo</h2>
        <p>Date nel fuso <?php echo esc_html(wp_timezone_string()); ?>. Gli errori richiedono un nuovo invio manuale. In caso di esito incerto, verifica prima la posta inviata.</p>
        <form method="get"><input type="hidden" name="page" value="eva-course-bookings"><input type="hidden" name="tab" value="invii_regalo">
            <label>Stato <select name="gift_status"><option value="">Tutti</option><?php foreach (self::statuses() as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
            <label>Periodo <select name="gift_period"><option value="">Tutti</option><option value="past" <?php selected($period, 'past'); ?>>Passati</option><option value="future" <?php selected($period, 'future'); ?>>Futuri</option></select></label>
            <label>Corso <select name="gift_product"><option value="">Tutti</option><?php foreach ($this->courses() as $course) : ?><option value="<?php echo esc_attr($course->ID); ?>" <?php selected($product, $course->ID); ?>><?php echo esc_html($course->post_title); ?></option><?php endforeach; ?></select></label>
            <button class="button">Filtra</button>
        </form><br>
        <table class="widefat striped"><thead><tr><th>Ordine / corso</th><th>Destinatario</th><th>Invio richiesto</th><th>Stato</th><th>Inviato il</th><th>Storico / errore</th><th>Azioni</th></tr></thead><tbody>
        <?php foreach ($rows as $row) :
            $order = wc_get_order($row->order_id);
            $item = $order ? $order->get_item($row->order_item_id) : false;
            if (! $item) { continue; }
            $state = $item->get_meta('_eva_gift_status');
            $data = (array) $item->get_meta('_eva_gift_data');
            $history = $item->get_meta('_eva_gift_history');
            $history = is_array($history) ? $history : array();
            ?>
            <tr><td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a><br><?php echo esc_html($item->get_name()); ?></td>
                <td><?php echo esc_html($data['recipient'] ?? ''); ?><br><?php echo esc_html($item->get_meta('_eva_gift_email')); ?></td>
                <td><?php echo esc_html(self::time($item->get_meta('_eva_gift_due'))); ?><br><small><?php echo esc_html('now' === ($data['mode'] ?? '') ? 'Dopo il pagamento' : ($data['date'] ?? '') . ' 08:00 ' . ($data['timezone'] ?? '')); ?></small></td>
                <td><?php echo esc_html(self::statuses()[$state] ?? $state); ?></td>
                <td><?php echo esc_html(self::time($item->get_meta('_eva_gift_sent_at'))); ?></td>
                <td><?php echo esc_html($item->get_meta('_eva_gift_error')); ?><details><summary><?php echo esc_html(count($history)); ?> eventi</summary>
                    <p>Da: <?php echo esc_html($data['sender'] ?? ''); ?><br><?php echo nl2br(esc_html($data['note'] ?? '')); ?></p>
                    <?php foreach ($history as $event) : ?><p><?php echo esc_html(self::time($event['at']) . ' · ' . $event['source'] . ' · ' . $event['status'] . ($event['user'] ? ' · utente #' . $event['user'] : '') . ' ' . $event['error']); ?></p><?php endforeach; ?>
                </details></td>
                <td><form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" target="_blank">
                    <?php wp_nonce_field('eva_voucher_settings'); ?><input type="hidden" name="action" value="eva_voucher_preview"><input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>"><input type="hidden" name="item_id" value="<?php echo esc_attr($item->get_id()); ?>"><button class="button">Anteprima</button>
                </form>
                <?php if (in_array($state, array('scheduled', 'failed', 'review'), true)) : ?>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="eva-gift-send" data-confirm="<?php echo esc_attr('review' === $state ? 'Esito incerto: verifica prima che il destinatario non abbia già ricevuto il voucher. Confermi un nuovo invio?' : 'Inviare subito il buono regalo?'); ?>">
                        <?php wp_nonce_field('eva_send_gift_' . $item->get_id()); ?><input type="hidden" name="action" value="eva_voucher_send"><input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>"><input type="hidden" name="item_id" value="<?php echo esc_attr($item->get_id()); ?>"><button class="button button-primary"><?php echo 'scheduled' === $state ? 'Invia ora' : 'Riprova'; ?></button>
                    </form>
                <?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (! $rows) : ?><tr><td colspan="7">Nessun invio regalo.</td></tr><?php endif; ?>
        </tbody></table>
        <?php echo wp_kses_post(paginate_links(array('base' => add_query_arg('paged', '%#%'), 'current' => $page, 'total' => (int) ceil($total / 25)))); ?>
        <?php
    }
}
