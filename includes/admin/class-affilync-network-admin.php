<?php
/**
 * Network Admin Page for Affilync WooCommerce.
 *
 * Provides a network-level admin page for managing Affilync across all
 * sites in a WordPress multisite installation. Lists all sites with their
 * Affilync connection status and allows bulk enable/disable operations.
 *
 * @package Affilync_WooCommerce
 * @since   1.5.0
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Network Admin handler.
 *
 * @since 1.5.0
 */
class Affilync_Network_Admin {

    /**
     * Network admin page slug.
     *
     * @var string
     */
    const PAGE_SLUG = 'affilync-network';

    /**
     * Multisite handler instance.
     *
     * @var Affilync_Multisite
     */
    private $multisite;

    /**
     * Constructor.
     *
     * @param Affilync_Multisite $multisite Multisite handler.
     */
    public function __construct( $multisite ) {
        $this->multisite = $multisite;
        $this->init_hooks();
    }

    /**
     * Register WordPress hooks for network admin functionality.
     */
    private function init_hooks() {
        add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
        add_action( 'network_admin_edit_affilync_network_settings', array( $this, 'handle_network_settings_save' ) );
        add_action( 'network_admin_edit_affilync_bulk_action', array( $this, 'handle_bulk_action' ) );
        add_action( 'network_admin_notices', array( $this, 'display_notices' ) );
    }

    /**
     * Add the Affilync page to the network admin menu.
     */
    public function add_network_menu() {
        add_menu_page(
            __( 'Affilync Network', 'affilync-woocommerce' ),
            __( 'Affilync', 'affilync-woocommerce' ),
            'manage_network_options',
            self::PAGE_SLUG,
            array( $this, 'render_network_page' ),
            'dashicons-admin-links',
            65
        );
    }

    /**
     * Render the network admin page.
     */
    public function render_network_page() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'affilync-woocommerce' ) );
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'sites';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Affilync Network Management', 'affilync-woocommerce' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( network_admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=sites' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'sites' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Sites', 'affilync-woocommerce' ); ?>
                </a>
                <a href="<?php echo esc_url( network_admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=settings' ) ); ?>"
                   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Network Settings', 'affilync-woocommerce' ); ?>
                </a>
            </nav>

            <div class="affilync-network-tab-content" style="margin-top: 20px;">
                <?php
                if ( 'settings' === $active_tab ) {
                    $this->render_settings_tab();
                } else {
                    $this->render_sites_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the sites list tab.
     *
     * Displays a table of all sites in the network with their Affilync
     * connection status and controls for bulk enable/disable.
     */
    private function render_sites_tab() {
        $sites_status = $this->multisite->get_all_sites_status();
        ?>
        <div class="affilync-network-stats" style="display: flex; gap: 20px; margin-bottom: 20px;">
            <div style="background: #fff; border: 1px solid #c3c4c7; padding: 15px 20px; flex: 1;">
                <h3 style="margin: 0 0 5px;"><?php esc_html_e( 'Total Sites', 'affilync-woocommerce' ); ?></h3>
                <span style="font-size: 24px; font-weight: 600;"><?php echo esc_html( count( $sites_status ) ); ?></span>
            </div>
            <div style="background: #fff; border: 1px solid #c3c4c7; padding: 15px 20px; flex: 1;">
                <h3 style="margin: 0 0 5px;"><?php esc_html_e( 'Enabled', 'affilync-woocommerce' ); ?></h3>
                <span style="font-size: 24px; font-weight: 600; color: #00a32a;">
                    <?php echo esc_html( count( array_filter( $sites_status, function( $s ) { return $s['enabled']; } ) ) ); ?>
                </span>
            </div>
            <div style="background: #fff; border: 1px solid #c3c4c7; padding: 15px 20px; flex: 1;">
                <h3 style="margin: 0 0 5px;"><?php esc_html_e( 'Connected', 'affilync-woocommerce' ); ?></h3>
                <span style="font-size: 24px; font-weight: 600; color: #0073aa;">
                    <?php echo esc_html( count( array_filter( $sites_status, function( $s ) { return $s['connected']; } ) ) ); ?>
                </span>
            </div>
        </div>

        <form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=affilync_bulk_action' ) ); ?>">
            <?php wp_nonce_field( 'affilync_bulk_action', '_affilync_nonce' ); ?>

            <div class="tablenav top">
                <div class="alignleft actions bulkactions">
                    <select name="affilync_action" id="affilync-bulk-action">
                        <option value=""><?php esc_html_e( 'Bulk Actions', 'affilync-woocommerce' ); ?></option>
                        <option value="enable"><?php esc_html_e( 'Enable Affilync', 'affilync-woocommerce' ); ?></option>
                        <option value="disable"><?php esc_html_e( 'Disable Affilync', 'affilync-woocommerce' ); ?></option>
                    </select>
                    <input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'affilync-woocommerce' ); ?>" />
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" id="cb-select-all-1" />
                        </td>
                        <th class="manage-column"><?php esc_html_e( 'Site', 'affilync-woocommerce' ); ?></th>
                        <th class="manage-column"><?php esc_html_e( 'Domain / Path', 'affilync-woocommerce' ); ?></th>
                        <th class="manage-column"><?php esc_html_e( 'Affilync Status', 'affilync-woocommerce' ); ?></th>
                        <th class="manage-column"><?php esc_html_e( 'Connection', 'affilync-woocommerce' ); ?></th>
                        <th class="manage-column"><?php esc_html_e( 'Brand ID', 'affilync-woocommerce' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $sites_status ) ) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'No sites found.', 'affilync-woocommerce' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $sites_status as $site ) : ?>
                            <tr>
                                <th class="check-column">
                                    <input type="checkbox" name="blog_ids[]" value="<?php echo esc_attr( $site['blog_id'] ); ?>" />
                                </th>
                                <td>
                                    <strong><?php echo esc_html( $site['site_name'] ); ?></strong>
                                    <div class="row-actions">
                                        <span>
                                            <a href="<?php echo esc_url( get_admin_url( $site['blog_id'], 'admin.php?page=affilync-settings' ) ); ?>">
                                                <?php esc_html_e( 'Site Settings', 'affilync-woocommerce' ); ?>
                                            </a>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php echo esc_html( $site['domain'] . $site['path'] ); ?>
                                </td>
                                <td>
                                    <?php if ( $site['enabled'] ) : ?>
                                        <span style="color: #00a32a;">
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <?php esc_html_e( 'Enabled', 'affilync-woocommerce' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="color: #72777c;">
                                            <span class="dashicons dashicons-minus"></span>
                                            <?php esc_html_e( 'Disabled', 'affilync-woocommerce' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( $site['connected'] ) : ?>
                                        <span style="color: #0073aa;">
                                            <span class="dashicons dashicons-admin-links"></span>
                                            <?php esc_html_e( 'Connected', 'affilync-woocommerce' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span style="color: #a00;">
                                            <span class="dashicons dashicons-editor-unlink"></span>
                                            <?php esc_html_e( 'Not Connected', 'affilync-woocommerce' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo esc_html( $site['brand_id'] ? $site['brand_id'] : '-' ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="manage-column column-cb check-column">
                            <input type="checkbox" id="cb-select-all-2" />
                        </td>
                        <th class="manage-column"><?php esc_html_e( 'Site', 'affilync-woocommerce' ); ?></th>
                        <th class="manage-column"><?php esc_html_e( 'Domain / Path', 'affilync-woocommerce' ); ?></th>
                        <th class="manage-column"><?php esc_html_e( 'Affilync Status', 'affilync-woocommerce' ); ?></th>
                        <th class="manage-column"><?php esc_html_e( 'Connection', 'affilync-woocommerce' ); ?></th>
                        <th class="manage-column"><?php esc_html_e( 'Brand ID', 'affilync-woocommerce' ); ?></th>
                    </tr>
                </tfoot>
            </table>
        </form>
        <?php
    }

    /**
     * Render the network settings tab.
     *
     * Allows super admins to configure network-wide defaults such as
     * auto-enabling on new sites and shared license configuration.
     */
    private function render_settings_tab() {
        $network_settings = $this->multisite->get_network_settings();
        ?>
        <form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=affilync_network_settings' ) ); ?>">
            <?php wp_nonce_field( 'affilync_network_settings', '_affilync_nonce' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="auto_enable_new_sites">
                            <?php esc_html_e( 'Auto-Enable New Sites', 'affilync-woocommerce' ); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="auto_enable_new_sites"
                                   id="auto_enable_new_sites"
                                   value="1"
                                   <?php checked( $network_settings['auto_enable_new_sites'] ); ?> />
                            <?php esc_html_e( 'Automatically enable Affilync when a new site is created in the network.', 'affilync-woocommerce' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="shared_license_key">
                            <?php esc_html_e( 'Shared License Key', 'affilync-woocommerce' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text"
                               name="shared_license_key"
                               id="shared_license_key"
                               class="regular-text"
                               value="<?php echo esc_attr( $network_settings['shared_license_key'] ); ?>"
                               placeholder="AFFILYNC-XXXX-XXXX-XXXX-XXXX" />
                        <p class="description">
                            <?php esc_html_e( 'Optional. A network-wide license key that new sites will inherit. Each site can still override with its own key.', 'affilync-woocommerce' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="enforce_network_plan">
                            <?php esc_html_e( 'Enforce Network Plan', 'affilync-woocommerce' ); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="enforce_network_plan"
                                   id="enforce_network_plan"
                                   value="1"
                                   <?php checked( $network_settings['enforce_network_plan'] ); ?> />
                            <?php esc_html_e( 'Enforce the shared license and plan settings across all sites (prevents per-site overrides).', 'affilync-woocommerce' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="default_api_url">
                            <?php esc_html_e( 'Default API URL', 'affilync-woocommerce' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="url"
                               name="default_api_url"
                               id="default_api_url"
                               class="regular-text"
                               value="<?php echo esc_attr( $network_settings['default_api_url'] ); ?>"
                               placeholder="https://api.affilync.com" />
                        <p class="description">
                            <?php esc_html_e( 'Override the default API URL for all sites in the network. Leave blank to use the default.', 'affilync-woocommerce' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Network Settings', 'affilync-woocommerce' ) ); ?>
        </form>
        <?php
    }

    /**
     * Handle network settings form submission.
     */
    public function handle_network_settings_save() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'affilync-woocommerce' ) );
        }

        check_admin_referer( 'affilync_network_settings', '_affilync_nonce' );

        $settings = array(
            'auto_enable_new_sites' => ! empty( $_POST['auto_enable_new_sites'] ),
            'shared_license_key'    => isset( $_POST['shared_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['shared_license_key'] ) ) : '',
            'enforce_network_plan'  => ! empty( $_POST['enforce_network_plan'] ),
            'default_api_url'       => isset( $_POST['default_api_url'] ) ? esc_url_raw( wp_unslash( $_POST['default_api_url'] ) ) : '',
        );

        $this->multisite->update_network_settings( $settings );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'              => self::PAGE_SLUG,
                    'tab'               => 'settings',
                    'affilync_updated'  => '1',
                ),
                network_admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Handle bulk enable/disable action from the sites table.
     */
    public function handle_bulk_action() {
        if ( ! current_user_can( 'manage_network_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'affilync-woocommerce' ) );
        }

        check_admin_referer( 'affilync_bulk_action', '_affilync_nonce' );

        $action   = isset( $_POST['affilync_action'] ) ? sanitize_key( wp_unslash( $_POST['affilync_action'] ) ) : '';
        $blog_ids = isset( $_POST['blog_ids'] ) && is_array( $_POST['blog_ids'] )
            ? array_map( 'absint', $_POST['blog_ids'] )
            : array();

        if ( empty( $action ) || empty( $blog_ids ) ) {
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'page'             => self::PAGE_SLUG,
                        'affilync_error'   => 'no_selection',
                    ),
                    network_admin_url( 'admin.php' )
                )
            );
            exit;
        }

        $count = 0;

        if ( 'enable' === $action ) {
            $results = $this->multisite->bulk_enable( $blog_ids );
            $count   = count( array_filter( $results ) );
        } elseif ( 'disable' === $action ) {
            $results = $this->multisite->bulk_disable( $blog_ids );
            $count   = count( array_filter( $results ) );
        }

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'             => self::PAGE_SLUG,
                    'affilync_updated' => '1',
                    'affilync_count'   => $count,
                    'affilync_action'  => $action,
                ),
                network_admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Display admin notices in the network admin area.
     */
    public function display_notices() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['affilync_updated'] ) && '1' === $_GET['affilync_updated'] ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $count  = isset( $_GET['affilync_count'] ) ? absint( $_GET['affilync_count'] ) : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $action = isset( $_GET['affilync_action'] ) ? sanitize_key( wp_unslash( $_GET['affilync_action'] ) ) : '';

            if ( $count > 0 && $action ) {
                $action_label = 'enable' === $action
                    ? _n( 'site enabled', 'sites enabled', $count, 'affilync-woocommerce' )
                    : _n( 'site disabled', 'sites disabled', $count, 'affilync-woocommerce' );

                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html(
                        sprintf(
                            /* translators: 1: count, 2: action label */
                            __( 'Affilync: %1$d %2$s successfully.', 'affilync-woocommerce' ),
                            $count,
                            $action_label
                        )
                    )
                );
            } else {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    esc_html__( 'Network settings saved.', 'affilync-woocommerce' )
                );
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['affilync_error'] ) && 'no_selection' === $_GET['affilync_error'] ) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                esc_html__( 'No sites selected or no action chosen. Please select sites and an action.', 'affilync-woocommerce' )
            );
        }
    }
}
