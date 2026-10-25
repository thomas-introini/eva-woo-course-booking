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

<h2><?php esc_html_e('Come raggiungerci', 'eva-course-bookings'); ?></h2>

<p>
    <?php esc_html_e('Ti aspettiamo presso la nostra torrefazione in', 'eva-course-bookings'); ?>
    <a href="<?php echo esc_url('https://maps.app.goo.gl/HkFkzm27H6KFMQF16'); ?>"><?php esc_html_e('Via Tucidide 56, 20134 Milano', 'eva-course-bookings'); ?></a>.<br>
    <?php esc_html_e('Il numero del nostro interno al citofono è 183.', 'eva-course-bookings'); ?>
</p>

<p>
    <?php esc_html_e('Se vieni in macchina, ti consigliamo questi parcheggi:', 'eva-course-bookings'); ?>
    <a href="<?php echo esc_url('https://maps.app.goo.gl/xaFTgSjihrmzz8Pn6'); ?>"><?php esc_html_e('Parcheggio 1', 'eva-course-bookings'); ?></a>
    <?php esc_html_e('oppure', 'eva-course-bookings'); ?>
    <a href="<?php echo esc_url('https://maps.app.goo.gl/1VZaZSJujXTKxG388'); ?>"><?php esc_html_e('Parcheggio 2', 'eva-course-bookings'); ?></a>.
</p>

<p>
    <?php esc_html_e('Se vieni con i mezzi, le fermate della metro più vicine sono', 'eva-course-bookings'); ?>
    <a href="<?php echo esc_url('https://maps.app.goo.gl/a1CPx48WBnXdT3ix5'); ?>">M4 Repetti</a>
    <?php esc_html_e('e', 'eva-course-bookings'); ?>
    <a href="<?php echo esc_url('https://maps.app.goo.gl/38B56CGzsmji1MvP6'); ?>">M4 Argonne</a>.
</p>

<p><a href="<?php echo esc_url('https://www.instagram.com/s/aGlnaGxpZ2h0OjE3OTA4MDg0MzQ0MTY0NjQ5?story_media_id=3821614722886423053&igsh=MWcwOHpzeHJrb2Q0NQ=='); ?>"><?php esc_html_e('Guarda il video: Come raggiungerci', 'eva-course-bookings'); ?></a></p>

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

