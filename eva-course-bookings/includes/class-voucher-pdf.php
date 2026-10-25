<?php
/** Personalized, single-page course vouchers. */
namespace Eva_Course_Bookings;

defined('ABSPATH') || exit;

class Voucher_Pdf
{
    const OPTION = 'eva_course_vouchers';

    public static function defaults()
    {
        return array(
            'error_email' => 'info@evacaffe.it',
            'width' => 297, 'height' => 210,
            'fields' => array(
                'recipient' => array('x' => 20, 'y' => 85, 'width' => 257, 'height' => 12, 'size' => 18, 'align' => 'C'),
                'sender' => array('x' => 20, 'y' => 105, 'width' => 257, 'height' => 10, 'size' => 14, 'align' => 'C'),
                'note' => array('x' => 25, 'y' => 125, 'width' => 247, 'height' => 45, 'size' => 12, 'align' => 'L'),
                'reference' => array('x' => 20, 'y' => 185, 'width' => 190, 'height' => 8, 'size' => 10, 'align' => 'L'),
                'quantity' => array('x' => 220, 'y' => 185, 'width' => 57, 'height' => 8, 'size' => 10, 'align' => 'R'),
            ),
        );
    }

    public static function settings()
    {
        return array_replace_recursive(self::defaults(), (array) get_option(self::OPTION, array()));
    }

    public static function validate_settings($input)
    {
        if (! is_array($input)) {
            return new \WP_Error('layout', 'Impostazioni voucher non valide.');
        }
        $settings = self::defaults();
        foreach (array('width', 'height') as $key) {
            if (! isset($input[$key]) || ! is_numeric($input[$key]) || $input[$key] < 50 || $input[$key] > 1000) {
                return new \WP_Error('layout', 'Dimensioni pagina non valide (50–1000 mm).');
            }
            $settings[$key] = (float) $input[$key];
        }
        if (! isset($input['error_email']) || ! is_string($input['error_email']) || ! is_email($input['error_email'])) {
            return new \WP_Error('email', 'Inserisci un indirizzo valido per le notifiche di errore.');
        }
        $settings['error_email'] = sanitize_email($input['error_email']);
        foreach ($settings['fields'] as $name => &$field) {
            $values = $input['fields'][$name] ?? array();
            foreach (array('x', 'y', 'width', 'height', 'size') as $key) {
                if (! isset($values[$key]) || ! is_numeric($values[$key]) || ! is_finite((float) $values[$key])) {
                    return new \WP_Error('layout', 'Coordinate e dimensioni dei campi non valide.');
                }
                $field[$key] = (float) $values[$key];
            }
            if ($field['x'] < 0 || $field['y'] < 0 || $field['width'] <= 2 || $field['height'] <= 0 ||
                $field['x'] + $field['width'] > $settings['width'] || $field['y'] + $field['height'] > $settings['height'] ||
                $field['size'] < 9 || $field['size'] > 48 || ! in_array($values['align'] ?? '', array('L', 'C', 'R'), true)) {
                return new \WP_Error('layout', 'I campi devono rientrare nella pagina; carattere tra 9 e 48 pt.');
            }
            $field['align'] = $values['align'];
        }
        return $settings;
    }

    public static function load_library()
    {
        if (! extension_loaded('mbstring') || ! extension_loaded('zlib') || ! is_readable(EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'vendor/autoload.php')) {
            throw new \RuntimeException('Voucher PDF non disponibili: servono mbstring, zlib e le dipendenze Composer del plugin.');
        }
        require_once EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'vendor/autoload.php';
    }

    public static function template_path($attachment_id)
    {
        $path = get_attached_file(absint($attachment_id));
        $uploads = wp_get_upload_dir();
        $base = realpath($uploads['basedir']);
        $real = $path ? realpath($path) : false;
        if ('application/pdf' !== get_post_mime_type(absint($attachment_id)) || ! $base || ! $real ||
            strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || ! is_readable($real)) {
            throw new \RuntimeException('Seleziona un PDF locale valido dalla libreria media.');
        }
        return $real;
    }

    public static function sample()
    {
        return array('recipient' => 'Giulia Rossi', 'sender' => 'Marco e amici', 'note' => "Buon compleanno!\nUn’esperienza speciale da condividere.", 'reference' => '12345', 'quantity' => 2);
    }

    public static function render($attachment_id, array $data, $settings = null)
    {
        self::load_library();
        $settings = self::validate_settings(null === $settings ? self::settings() : $settings);
        if (is_wp_error($settings)) {
            throw new \RuntimeException($settings->get_error_message());
        }
        $pdf = new class extends \setasign\Fpdi\Tfpdf\Fpdi {
            public function __construct()
            {
                parent::__construct();
                $this->fontpath = EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'vendor/setasign/tfpdf/font/';
            }

            public function supports($text)
            {
                foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $char) {
                    $code = mb_ord($char, 'UTF-8');
                    if (in_array($char, array("\n", "\r", "\t"), true)) {
                        continue;
                    }
                    $widths = $this->CurrentFont['cw'];
                    if (! isset($widths[$code * 2 + 1]) || substr($widths, $code * 2, 2) === "\0\0") {
                        return false;
                    }
                }
                return true;
            }
        };
        if (1 !== $pdf->setSourceFile(self::template_path($attachment_id))) {
            throw new \RuntimeException('Il modello voucher deve contenere una sola pagina.');
        }
        $page = $pdf->importPage(1);
        $dimensions = $pdf->getTemplateSize($page);
        if (abs($dimensions['width'] - $settings['width']) > 0.5 || abs($dimensions['height'] - $settings['height']) > 0.5) {
            throw new \RuntimeException(sprintf('Il PDF misura %.1f × %.1f mm; il layout è %.1f × %.1f mm. Imposta dimensioni e coordinate corrispondenti nelle impostazioni voucher.', $dimensions['width'], $dimensions['height'], $settings['width'], $settings['height']));
        }
        $pdf->AddPage($dimensions['orientation'], array($dimensions['width'], $dimensions['height']));
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->useImportedPage($page);
        $pdf->AddFont('DejaVu', '', 'DejaVuSansCondensed.ttf', true);
        $pdf->SetFont('DejaVu', '', 12);
        $text = array(
            'recipient' => 'Per: ' . ($data['recipient'] ?? ''),
            'sender' => 'Da: ' . ($data['sender'] ?? ''),
            'note' => $data['note'] ?? '',
            'reference' => 'Riferimento prenotazione: ' . ($data['reference'] ?? ''),
            'quantity' => 'Partecipanti: ' . ($data['quantity'] ?? 1),
        );
        foreach ($text as $key => $value) {
            $value = str_replace(array("\r\n", "\r", "\t"), array("\n", "\n", '    '), (string) $value);
            if ('' === $value) {
                continue;
            }
            if (! $pdf->supports($value)) {
                throw new \RuntimeException('Il campo ' . $key . ' contiene caratteri non supportati dal font del voucher. Rimuovi emoji o simboli non supportati.');
            }
            $field = $settings['fields'][$key];
            for ($size = $field['size']; ; $size = max(9, $size - 0.5)) {
                $pdf->SetFontSize($size);
                $lines = self::wrap($pdf, $value, $field['width'] - 2);
                $line_height = $size * 25.4 / 72 * 1.3;
                if (count($lines) * $line_height <= $field['height'] && max(array_map(array($pdf, 'GetStringWidth'), $lines)) <= $field['width'] - 2) {
                    break;
                }
                if ($size <= 9) {
                    throw new \RuntimeException('Il campo ' . $key . ' non entra nel voucher. Riduci il testo o amplia il campo nel layout.');
                }
            }
            foreach ($lines as $index => $line) {
                $pdf->SetXY($field['x'], $field['y'] + $index * $line_height);
                $pdf->Cell($field['width'], $line_height, $line, 0, 0, $field['align']);
            }
        }
        return $pdf->Output('S');
    }

    private static function wrap($pdf, $text, $width)
    {
        $lines = array();
        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            foreach (preg_split('//u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) as $char) {
                if ($line !== '' && $pdf->GetStringWidth($line . $char) > $width) {
                    $space = mb_strrpos($line, ' ', 0, 'UTF-8');
                    if (false !== $space && $space > 0) {
                        $lines[] = mb_substr($line, 0, $space, 'UTF-8');
                        $line = mb_substr($line, $space + 1, null, 'UTF-8');
                    } else {
                        $lines[] = $line;
                        $line = '';
                    }
                }
                $line .= $char;
            }
            $lines[] = $line;
        }
        return $lines;
    }
}
