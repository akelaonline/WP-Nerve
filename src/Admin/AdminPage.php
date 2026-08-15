<?php

/**
 * Minimal connection and diagnostics screen.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Admin;

final class AdminPage
{
    public function registerMenu(): void
    {
        add_management_page(
            __('WPNerve', 'wp-nerve'),
            __('WPNerve', 'wp-nerve'),
            'manage_options',
            'wp-nerve',
            array($this, 'render')
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $endpoint = rest_url('wp-nerve/v1/mcp');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('WPNerve', 'wp-nerve'); ?></h1>
            <p><?php echo esc_html__('Secure MCP access to the native WordPress Abilities API.', 'wp-nerve'); ?></p>

            <h2><?php echo esc_html__('Connection', 'wp-nerve'); ?></h2>
            <table class="widefat striped" style="max-width: 900px">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('MCP endpoint', 'wp-nerve'); ?></th>
                        <td><code><?php echo esc_html($endpoint); ?></code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Authentication', 'wp-nerve'); ?></th>
                        <td><?php echo esc_html__('WordPress Application Password over HTTPS', 'wp-nerve'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Protocol versions', 'wp-nerve'); ?></th>
                        <td><code>2026-07-28</code>, <code>2025-11-25</code>, <code>2025-06-18</code></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Default access', 'wp-nerve'); ?></th>
                        <td>
                            <?php echo esc_html__('Authenticated users with the edit_posts capability', 'wp-nerve'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__('Secure setup', 'wp-nerve'); ?></h2>
            <ol>
                <li><?php echo esc_html__('Open the WordPress profile for the user the agent will act as.', 'wp-nerve'); ?></li>
                <li>
                    <?php echo esc_html__('Create a dedicated Application Password named WPNerve.', 'wp-nerve'); ?>
                </li>
                <li>
                    <?php
                    echo esc_html__(
                        'Configure the MCP client with the endpoint, username, and generated Application Password.',
                        'wp-nerve'
                    );
                    ?>
                </li>
                <li>
                    <?php
                    echo esc_html__(
                        'Revoke that Application Password immediately if the client or device is lost.',
                        'wp-nerve'
                    );
                    ?>
                </li>
            </ol>

            <p class="description">
                <?php
                echo esc_html__(
                    'WPNerve never displays or stores the Application Password. Tool arguments and credentials are excluded from the audit log.',
                    'wp-nerve'
                );
                ?>
            </p>
        </div>
        <?php
    }
}
