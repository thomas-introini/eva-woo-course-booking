<?php
/**
 * Course Reminder Email (HTML)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/course-reminder.php.
 *
 * @package Eva_Course_Bookings
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action('woocommerce_email_header', $email_heading, $email);
?>

<?php if ($customer_name) : ?>
<p><?php printf(esc_html__('Ciao %s,', 'eva-course-bookings'), esc_html($customer_name)); ?></p>
<?php else : ?>
<p><?php esc_html_e('Ciao,', 'eva-course-bookings'); ?></p>
<?php endif; ?>

<p><?php esc_html_e('Ti ricordiamo che hai una prenotazione per il seguente corso:', 'eva-course-bookings'); ?></p>

<h2 style="color: #7f54b3; display: block; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; font-size: 18px; font-weight: bold; line-height: 130%; margin: 0 0 18px; text-align: left;">
    <?php echo esc_html($course_name); ?>
</h2>

<table cellspacing="0" cellpadding="6" border="1" style="width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 1px solid #e5e5e5;">
    <tbody>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5; width: 30%;">
                <?php esc_html_e('Data', 'eva-course-bookings'); ?>
            </th>
            <td style="text-align: left; padding: 12px; border: 1px solid #e5e5e5;">
                <strong><?php echo esc_html($course_date); ?></strong>
            </td>
        </tr>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;">
                <?php esc_html_e('Orario', 'eva-course-bookings'); ?>
            </th>
            <td style="text-align: left; padding: 12px; border: 1px solid #e5e5e5;">
                <strong>
                    <?php echo esc_html($course_time); ?>
                    <?php if ($course_end_time) : ?>
                        - <?php echo esc_html($course_end_time); ?>
                    <?php endif; ?>
                </strong>
            </td>
        </tr>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;">
                <?php esc_html_e('Partecipanti', 'eva-course-bookings'); ?>
            </th>
            <td style="text-align: left; padding: 12px; border: 1px solid #e5e5e5;">
                <strong><?php echo esc_html($participant_count); ?></strong>
            </td>
        </tr>
        <?php if ($location) : ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;">
                <?php esc_html_e('Luogo', 'eva-course-bookings'); ?>
            </th>
            <td style="text-align: left; padding: 12px; border: 1px solid #e5e5e5;">
                <?php echo esc_html($location); ?>
            </td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php if ($notes) : ?>
<div style="background-color: #fffbcc; border-left: 4px solid #ffeb3b; padding: 15px; margin-bottom: 20px;">
    <h3 style="margin: 0 0 10px; font-size: 14px; font-weight: bold; color: #333;">
        <?php esc_html_e('Note importanti', 'eva-course-bookings'); ?>
    </h3>
    <p style="margin: 0; color: #555;">
        <?php echo nl2br(esc_html($notes)); ?>
    </p>
</div>
<?php endif; ?>

<?php
/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ($additional_content) {
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
}
?>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action('woocommerce_email_footer', $email);

