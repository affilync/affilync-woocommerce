<?php
/**
 * Connected Email Template.
 *
 * @package Affilync_WooCommerce
 * @var string $site_name Site name.
 * @var string $site_url Site URL.
 * @var string $settings_url Settings page URL.
 * @var string $brand_id Brand ID.
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;font-family:Arial,sans-serif;background-color:#f6f7f7;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f6f7f7;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background:linear-gradient(135deg,#0066e6 0%,#004bb5 100%);padding:30px;text-align:center;">
                            <img src="<?php echo esc_url( AFFILYNC_PLUGIN_URL . 'assets/images/logo-white.png' ); ?>" alt="Affilync" style="height:40px;" />
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding:40px 30px;">
                            <h1 style="margin:0 0 20px;color:#1a1a1a;font-size:24px;font-weight:600;">
                                <?php esc_html_e( 'Successfully Connected!', 'affilync-woocommerce' ); ?>
                            </h1>

                            <p style="margin:0 0 20px;color:#4a5568;font-size:16px;line-height:1.6;">
                                <?php esc_html_e( 'Great news! Your WooCommerce store is now connected to Affilync.', 'affilync-woocommerce' ); ?>
                            </p>

                            <p style="margin:0 0 30px;color:#4a5568;font-size:16px;line-height:1.6;">
                                <?php esc_html_e( 'You can now start tracking affiliate conversions, syncing products, and growing your affiliate program.', 'affilync-woocommerce' ); ?>
                            </p>

                            <!-- Stats Box -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border-radius:8px;margin-bottom:30px;">
                                <tr>
                                    <td style="padding:20px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:10px;text-align:center;">
                                                    <span style="display:block;font-size:14px;color:#718096;margin-bottom:5px;"><?php esc_html_e( 'Store', 'affilync-woocommerce' ); ?></span>
                                                    <span style="display:block;font-size:16px;color:#1a1a1a;font-weight:600;"><?php echo esc_html( $site_name ); ?></span>
                                                </td>
                                                <td style="padding:10px;text-align:center;border-left:1px solid #e2e8f0;">
                                                    <span style="display:block;font-size:14px;color:#718096;margin-bottom:5px;"><?php esc_html_e( 'Status', 'affilync-woocommerce' ); ?></span>
                                                    <span style="display:block;font-size:16px;color:#00ff88;font-weight:600;">✓ <?php esc_html_e( 'Connected', 'affilync-woocommerce' ); ?></span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            <!-- CTA Button -->
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <a href="<?php echo esc_url( $settings_url ?? admin_url( 'admin.php?page=affilync-settings' ) ); ?>"
                                           style="display:inline-block;background:#0066e6;color:#ffffff;padding:14px 32px;text-decoration:none;border-radius:6px;font-size:16px;font-weight:600;">
                                            <?php esc_html_e( 'View Dashboard', 'affilync-woocommerce' ); ?>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Quick Start -->
                    <tr>
                        <td style="padding:0 30px 30px;">
                            <h2 style="margin:0 0 15px;color:#1a1a1a;font-size:18px;font-weight:600;">
                                <?php esc_html_e( 'Quick Start Guide', 'affilync-woocommerce' ); ?>
                            </h2>
                            <ul style="margin:0;padding:0 0 0 20px;color:#4a5568;font-size:14px;line-height:1.8;">
                                <li><?php esc_html_e( 'Configure your tracking settings', 'affilync-woocommerce' ); ?></li>
                                <li><?php esc_html_e( 'Sync your products to the marketplace', 'affilync-woocommerce' ); ?></li>
                                <li><?php esc_html_e( 'Verify your brand for trust badges', 'affilync-woocommerce' ); ?></li>
                                <li><?php esc_html_e( 'Invite affiliates to promote your products', 'affilync-woocommerce' ); ?></li>
                            </ul>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f8fafc;padding:20px 30px;text-align:center;border-top:1px solid #e2e8f0;">
                            <p style="margin:0 0 10px;color:#718096;font-size:14px;">
                                <?php esc_html_e( 'Sent by Affilync for WooCommerce', 'affilync-woocommerce' ); ?>
                            </p>
                            <p style="margin:0;color:#718096;font-size:12px;">
                                <a href="<?php echo esc_url( $site_url ); ?>" style="color:#0066e6;text-decoration:none;"><?php echo esc_html( $site_name ); ?></a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
