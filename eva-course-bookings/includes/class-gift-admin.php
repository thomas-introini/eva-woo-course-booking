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
        add_action('woocommerce_product_options_general_product_data', array($this, 'product_field'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_eva_voucher_settings', array($this, 'save_settings'));
        add_action('admin_post_eva_voucher_preview', array($this, 'preview'));
        add_action('admin_post_eva_voucher_send', array($this, 'send'));
    }

    public function assets($hook)
    {
        if (strpos($hook, 'eva-course-bookings') !== false || ('product' === get_post_type() && in_array($hook, array('post.php', 'post-new.php'), true))) {
            wp_enqueue_media();
            wp_enqueue_script('eva-gift-admin', EVA_COURSE_BOOKINGS_PLUGIN_URL . 'assets/js/gift-admin.js', array('jquery'), EVA_COURSE_BOOKINGS_VERSION, true);
        }
    }

    public function product_field()
    {
        global $post;
        $id = absint(get_post_meta($post->ID, '_eva_gift_pdf_id', true));
        ?>
        <p class="form-field">
            <label for="eva-gift-pdf-id">Modello buono regalo PDF</label>
            <input type="hidden" name="_eva_gift_pdf_id" id="eva-gift-pdf-id" value="<?php echo esc_attr($id); ?>">
            <span id="eva-gift-pdf-name"><?php echo esc_html($id ? get_the_title($id) : 'Nessun modello'); ?></span>
            <button type="button" class="button" id="eva-gift-pdf-select">Scegli PDF</button>
            <button type="button" class="button" id="eva-gift-pdf-clear">Rimuovi</button>
            <span class="description">Un PDF valido abilita l’invio programmato per i nuovi regali (richiede la prenotazione senza data). Layout e anteprima in Corsi → Impostazioni.</span>
        </p>
        <?php
    }

    public function save_product($product_id)
    {
        if (! current_user_can('manage_woocommerce') || ! isset($_POST['_eva_gift_pdf_id']) || ! is_scalar($_POST['_eva_gift_pdf_id'])) {
            return;
        }
        $id = absint($_POST['_eva_gift_pdf_id']);
        if ($id && ('attachment' !== get_post_type($id) || 'application/pdf' !== get_post_mime_type($id))) {
            \WC_Admin_Meta_Boxes::add_error('Modello voucher non salvato: seleziona un PDF dalla libreria media.');
            return;
        }
        update_post_meta($product_id, '_eva_gift_pdf_id', $id);
        if (! $id) {
            return;
        }
        try {
            Voucher_Pdf::render($id, Voucher_Pdf::sample());
        } catch (\Throwable $error) {
            \WC_Admin_Meta_Boxes::add_error('PDF associato al corso, ma il buono non è ancora pronto all’invio. Verifica il PDF e configura il layout in WooCommerce → Corsi → Impostazioni: ' . $error->getMessage());
        }
    }

    private function authorize($nonce)
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Permesso negato.', '', array('response' => 403));
        }
        check_admin_referer($nonce);
    }

    private function notice($message, $error, $tab)
    {
        set_transient('eva_gift_notice_' . get_current_user_id(), array('message' => $message, 'error' => $error), 60);
        wp_safe_redirect(admin_url('admin.php?page=eva-course-bookings&tab=' . $tab));
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
        $settings = Voucher_Pdf::validate_settings(wp_unslash($_POST['voucher'] ?? array()));
        if (is_wp_error($settings)) {
            $this->notice($settings->get_error_message(), true, 'impostazioni');
        }
        update_option(Voucher_Pdf::OPTION, $settings, false);
        $this->notice('Impostazioni voucher salvate. Le modifiche si applicano ai regali non ancora inviati.', false, 'impostazioni');
    }

    public function preview()
    {
        $this->authorize('eva_voucher_settings');
        try {
            $settings = isset($_POST['voucher']) ? Voucher_Pdf::validate_settings(wp_unslash($_POST['voucher'])) : Voucher_Pdf::settings();
            if (is_wp_error($settings)) {
                throw new \RuntimeException($settings->get_error_message());
            }
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
                $product_id = absint($_POST['product_id'] ?? 0);
                foreach (array('recipient', 'sender', 'note') as $key) {
                    $value = $_POST['sample'][$key] ?? $data[$key];
                    if (! is_string($value) || mb_strlen($value) > ('note' === $key ? 500 : 100)) {
                        throw new \RuntimeException('Testo anteprima non valido o troppo lungo.');
                    }
                    $data[$key] = sanitize_textarea_field(wp_unslash($value));
                }
            }
            $bytes = Voucher_Pdf::render(get_post_meta($product_id, '_eva_gift_pdf_id', true), $data, $settings);
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
        $this->show_notice();
        $settings = Voucher_Pdf::settings();
        $labels = array('recipient' => 'Destinatario', 'sender' => 'Mittente', 'note' => 'Messaggio', 'reference' => 'Riferimento prenotazione', 'quantity' => 'Partecipanti');
        ?>
        <hr><h2>Buoni regalo PDF</h2>
        <p>Carica un PDF di una pagina per ogni corso dalla scheda prodotto. Consigliato: A4 orizzontale, PDF 1.4 non protetto e senza campi modulo. Il plugin aggiunge i testi nelle aree vuote. Coordinate in millimetri dall’angolo superiore sinistro.</p>
        <p>Invio alle 08:00 nel fuso del negozio: <strong><?php echo esc_html(wp_timezone_string()); ?></strong>. Per puntualità, configura cron sul server ogni minuto. “Inviato” indica accettazione da parte del sistema email, non consegna nella casella.</p>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <?php wp_nonce_field('eva_voucher_settings'); ?>
            <p><label>Email notifiche errori <input type="email" required name="voucher[error_email]" value="<?php echo esc_attr($settings['error_email']); ?>"></label></p>
            <p><?php foreach (array('width' => 'Larghezza pagina', 'height' => 'Altezza pagina') as $key => $label) : ?>
                <label><?php echo esc_html($label); ?> <input type="number" required min="50" max="1000" step="0.1" name="voucher[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($settings[$key]); ?>" style="width:90px"> mm</label>
            <?php endforeach; ?></p>
            <table class="widefat striped"><thead><tr><th>Campo</th><th>X</th><th>Y</th><th>Larghezza</th><th>Altezza</th><th>Carattere (pt)</th><th>Allineamento</th></tr></thead><tbody>
                <?php foreach ($settings['fields'] as $name => $field) : ?>
                    <tr><th scope="row"><?php echo esc_html($labels[$name]); ?></th>
                    <?php foreach (array('x', 'y', 'width', 'height', 'size') as $key) : ?>
                        <td><input aria-label="<?php echo esc_attr($labels[$name] . ' ' . $key); ?>" type="number" required step="0.1" min="<?php echo 'size' === $key ? '9' : '0'; ?>" name="voucher[fields][<?php echo esc_attr($name); ?>][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($field[$key]); ?>" style="width:80px"></td>
                    <?php endforeach; ?>
                    <td><select aria-label="<?php echo esc_attr($labels[$name] . ' allineamento'); ?>" name="voucher[fields][<?php echo esc_attr($name); ?>][align]">
                        <?php foreach (array('L' => 'Sinistra', 'C' => 'Centro', 'R' => 'Destra') as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($field['align'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                    </select></td></tr>
                <?php endforeach; ?>
            </tbody></table>
            <p><button class="button button-primary" type="submit" name="action" value="eva_voucher_settings">Salva impostazioni voucher</button></p>
            <h3>Anteprima con il layout attuale, anche prima del salvataggio</h3>
            <p><label>Corso <select name="product_id"><option value="">Scegli un corso con PDF</option>
                <?php foreach ($this->courses() as $course) : if (! get_post_meta($course->ID, '_eva_gift_pdf_id', true)) { continue; } ?>
                    <option value="<?php echo esc_attr($course->ID); ?>"><?php echo esc_html($course->post_title); ?></option>
                <?php endforeach; ?>
            </select></label></p>
            <p class="description">Se un corso non compare, abilita “Abilita prenotazione corso” nel prodotto, scegli il modello PDF e premi “Aggiorna”. Il PDF può essere associato prima di completare il layout.</p>
            <?php foreach (array('recipient' => 'Destinatario', 'sender' => 'Mittente') as $key => $label) : ?>
                <p><label><?php echo esc_html($label); ?> <input type="text" maxlength="100" name="sample[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr(Voucher_Pdf::sample()[$key]); ?>"></label></p>
            <?php endforeach; ?>
            <p><label>Messaggio<br><textarea name="sample[note]" maxlength="500" rows="4" cols="70"><?php echo esc_textarea(Voucher_Pdf::sample()['note']); ?></textarea></label></p>
            <p><button class="button" type="submit" name="action" value="eva_voucher_preview" formtarget="eva-voucher-preview">Genera anteprima PDF</button></p>
        </form>
        <iframe name="eva-voucher-preview" title="Anteprima buono regalo" style="width:100%;height:650px;border:1px solid #ccc" src="about:blank"></iframe>
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
