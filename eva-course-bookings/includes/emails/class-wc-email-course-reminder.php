<?php

/**
 * Course Reminder Email.
 *
 * An email sent to customers to remind them about an upcoming course.
 *
 * @package Eva_Course_Bookings
 */

namespace Eva_Course_Bookings;

// Prevent direct file access.
if (! defined('ABSPATH')) {
  exit;
}

if (! class_exists('WC_Email')) {
  return;
}

/**
 * Class WC_Email_Course_Reminder
 *
 * Customer course reminder email.
 */
class WC_Email_Course_Reminder extends \WC_Email
{

  /**
   * Course name.
   *
   * @var string
   */
  public $course_name = '';

  /**
   * Course date (formatted).
   *
   * @var string
   */
  public $course_date = '';

  /**
   * Course start time.
   *
   * @var string
   */
  public $course_time = '';

  /**
   * Course end time.
   *
   * @var string
   */
  public $course_end_time = '';

  /**
   * Participant count.
   *
   * @var int
   */
  public $participant_count = 0;

  /**
   * Customer name.
   *
   * @var string
   */
  public $customer_name = '';

  /**
   * Customer email.
   *
   * @var string
   */
  public $customer_email_address = '';

  /**
   * Location.
   *
   * @var string
   */
  public $location = '';

  /**
   * Custom notes.
   *
   * @var string
   */
  public $notes = '';

  /**
   * Constructor.
   */
  public function __construct()
  {
    $this->id             = 'eva_course_reminder';
    $this->customer_email = true;
    $this->title          = __('Promemoria corso', 'eva-course-bookings');
    $this->description    = __('Email di promemoria inviata ai partecipanti per ricordare un corso imminente.', 'eva-course-bookings');

    $this->template_html  = 'emails/course-reminder.php';
    $this->template_plain = 'emails/plain/course-reminder.php';
    $this->template_base  = EVA_COURSE_BOOKINGS_PLUGIN_DIR . 'templates/';

    // Placeholders.
    $this->placeholders = array_merge(
      array(
        '{course_name}'       => '',
        '{course_date}'       => '',
        '{course_time}'       => '',
        '{course_end_time}'   => '',
        '{participant_count}' => '',
        '{customer_name}'     => '',
        '{location}'          => '',
        '{notes}'             => '',
      ),
      $this->placeholders
    );

    // Triggers - manual only, no automatic triggers.
    // This email is sent manually by admin.

    // Call parent constructor.
    parent::__construct();

    // Default recipient is empty (set dynamically).
    $this->recipient = '';
  }

  /**
   * Get email subject.
   *
   * @return string
   */
  public function get_default_subject()
  {
    return __('Promemoria: il tuo corso {course_name} è in arrivo!', 'eva-course-bookings');
  }

  /**
   * Get email heading.
   *
   * @return string
   */
  public function get_default_heading()
  {
    return __('Promemoria corso', 'eva-course-bookings');
  }

  /**
   * Trigger the email.
   *
   * @param array $args Email arguments.
   *                    - recipient: Customer email address.
   *                    - customer_name: Customer full name.
   *                    - course_name: Name of the course/product.
   *                    - course_date: Formatted date string.
   *                    - course_time: Start time.
   *                    - course_end_time: End time (optional).
   *                    - participant_count: Number of participants.
   * @return bool Whether the email was sent.
   */
  public function trigger($args)
  {
    $this->setup_locale();

    // Validate required fields.
    if (empty($args['recipient']) || ! is_email($args['recipient'])) {
      $this->restore_locale();
      return false;
    }

    // Set recipient.
    $this->recipient = sanitize_email($args['recipient']);

    // Set email data.
    $this->customer_name          = isset($args['customer_name']) ? sanitize_text_field($args['customer_name']) : '';
    $this->customer_email_address = $this->recipient;
    $this->course_name            = isset($args['course_name']) ? sanitize_text_field($args['course_name']) : '';
    $this->course_date            = isset($args['course_date']) ? sanitize_text_field($args['course_date']) : '';
    $this->course_time            = isset($args['course_time']) ? sanitize_text_field($args['course_time']) : '';
    $this->course_end_time        = isset($args['course_end_time']) ? sanitize_text_field($args['course_end_time']) : '';
    $this->participant_count      = isset($args['participant_count']) ? absint($args['participant_count']) : 0;

    // Get location and notes from settings.
    $this->location = $this->get_option('location', '');
    $this->notes    = $this->get_option('notes', '');

    // Set placeholders.
    $this->placeholders['{course_name}']       = $this->course_name;
    $this->placeholders['{course_date}']       = $this->course_date;
    $this->placeholders['{course_time}']       = $this->course_time;
    $this->placeholders['{course_end_time}']   = $this->course_end_time;
    $this->placeholders['{participant_count}'] = $this->participant_count;
    $this->placeholders['{customer_name}']     = $this->customer_name;
    $this->placeholders['{location}']          = $this->location;
    $this->placeholders['{notes}']             = $this->notes;

    // Check if email is enabled.
    if (! $this->is_enabled()) {
      $this->restore_locale();
      return false;
    }

    $result = $this->send(
      $this->get_recipient(),
      $this->get_subject(),
      $this->get_content(),
      $this->get_headers(),
      $this->get_attachments()
    );

    $this->restore_locale();

    return $result;
  }

  /**
   * Get content HTML.
   *
   * @return string
   */
  public function get_content_html()
  {
    return wc_get_template_html(
      $this->template_html,
      array(
        'email_heading'     => $this->get_heading(),
        'course_name'       => $this->course_name,
        'course_date'       => $this->course_date,
        'course_time'       => $this->course_time,
        'course_end_time'   => $this->course_end_time,
        'participant_count' => $this->participant_count,
        'customer_name'     => $this->customer_name,
        'location'          => $this->location,
        'notes'             => $this->notes,
        'sent_to_admin'     => false,
        'plain_text'        => false,
        'email'             => $this,
        'additional_content' => $this->get_additional_content(),
      ),
      '',
      $this->template_base
    );
  }

  /**
   * Get content plain.
   *
   * @return string
   */
  public function get_content_plain()
  {
    return wc_get_template_html(
      $this->template_plain,
      array(
        'email_heading'     => $this->get_heading(),
        'course_name'       => $this->course_name,
        'course_date'       => $this->course_date,
        'course_time'       => $this->course_time,
        'course_end_time'   => $this->course_end_time,
        'participant_count' => $this->participant_count,
        'customer_name'     => $this->customer_name,
        'location'          => $this->location,
        'notes'             => $this->notes,
        'sent_to_admin'     => false,
        'plain_text'        => true,
        'email'             => $this,
        'additional_content' => $this->get_additional_content(),
      ),
      '',
      $this->template_base
    );
  }

  /**
   * Default content to show below main email content.
   *
   * @return string
   */
  public function get_default_additional_content()
  {
    return __('Ti aspettiamo! Per qualsiasi domanda, non esitare a contattarci.', 'eva-course-bookings');
  }

  /**
   * Initialize form fields for settings.
   */
  public function init_form_fields()
  {
    // Start with parent fields.
    parent::init_form_fields();

    $placeholder_text = sprintf(
      /* translators: %s: list of placeholders */
      __('Segnaposto disponibili: %s', 'eva-course-bookings'),
      '<code>{course_name}</code>, <code>{course_date}</code>, <code>{course_time}</code>, <code>{course_end_time}</code>, <code>{participant_count}</code>, <code>{customer_name}</code>, <code>{location}</code>, <code>{notes}</code>, <code>{site_title}</code>'
    );

    $this->form_fields = array(
      'enabled' => array(
        'title'   => __('Abilita/Disabilita', 'eva-course-bookings'),
        'type'    => 'checkbox',
        'label'   => __('Abilita questa email', 'eva-course-bookings'),
        'default' => 'yes',
      ),
      'subject' => array(
        'title'       => __('Oggetto', 'eva-course-bookings'),
        'type'        => 'text',
        'desc_tip'    => true,
        'description' => $placeholder_text,
        'placeholder' => $this->get_default_subject(),
        'default'     => '',
      ),
      'heading' => array(
        'title'       => __('Intestazione email', 'eva-course-bookings'),
        'type'        => 'text',
        'desc_tip'    => true,
        'description' => $placeholder_text,
        'placeholder' => $this->get_default_heading(),
        'default'     => '',
      ),
      'location' => array(
        'title'       => __('Luogo del corso', 'eva-course-bookings'),
        'type'        => 'text',
        'desc_tip'    => true,
        'description' => __('Indirizzo o luogo dove si terrà il corso. Verrà mostrato nell\'email.', 'eva-course-bookings'),
        'placeholder' => __('Es: Via Roma 1, Milano', 'eva-course-bookings'),
        'default'     => '',
      ),
      'notes' => array(
        'title'       => __('Note personalizzate', 'eva-course-bookings'),
        'type'        => 'textarea',
        'desc_tip'    => true,
        'description' => __('Note aggiuntive da includere nell\'email (es. cosa portare, istruzioni speciali).', 'eva-course-bookings'),
        'placeholder' => __('Es: Ricordati di portare un tappetino e abbigliamento comodo.', 'eva-course-bookings'),
        'default'     => '',
        'css'         => 'width: 400px; height: 100px;',
      ),
      'additional_content' => array(
        'title'       => __('Contenuto aggiuntivo', 'eva-course-bookings'),
        'description' => __('Testo visualizzato sotto il contenuto principale dell\'email.', 'eva-course-bookings') . ' ' . $placeholder_text,
        'css'         => 'width: 400px; height: 75px;',
        'placeholder' => __('N/A', 'eva-course-bookings'),
        'type'        => 'textarea',
        'default'     => $this->get_default_additional_content(),
        'desc_tip'    => true,
      ),
      'email_type' => array(
        'title'       => __('Tipo email', 'eva-course-bookings'),
        'type'        => 'select',
        'description' => __('Scegli il formato dell\'email da inviare.', 'eva-course-bookings'),
        'default'     => 'html',
        'class'       => 'email_type wc-enhanced-select',
        'options'     => $this->get_email_type_options(),
        'desc_tip'    => true,
      ),
    );
  }
}
