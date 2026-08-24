<?php

/**
 * In-product documentation for WPNerve administrators.
 *
 * @package WPNerve
 */

declare(strict_types=1);

namespace WPNerve\Admin;

final class DocumentationPage
{
    public function registerMenu(): void
    {
        if (function_exists('add_submenu_page')) {
            add_submenu_page(
                'wp-nerve',
                __('WPNerve Documentation', 'wp-nerve'),
                __('Documentation', 'wp-nerve'),
                'manage_options',
                'wp-nerve-documentation',
                array($this, 'render')
            );
            return;
        }

        add_management_page(
            __('WPNerve Documentation', 'wp-nerve'),
            __('WPNerve Documentation', 'wp-nerve'),
            'manage_options',
            'wp-nerve-documentation',
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
        <div class="wrap wpn-admin">
            <header class="wpn-hero">
                <div class="wpn-hero__brand">
                    <span class="wpn-brandmark"><span class="dashicons dashicons-networking"></span></span>
                    <div>
                        <span class="wpn-kicker"><?php echo esc_html__('Documentation', 'wp-nerve'); ?></span>
                        <h1><?php echo esc_html__('WPNerve Operator Guide', 'wp-nerve'); ?></h1>
                        <p><?php echo esc_html__('Connect agents to WordPress without exposing arbitrary shell, SQL, or filesystem access.', 'wp-nerve'); ?></p>
                    </div>
                </div>
                <div class="wpn-hero__actions">
                    <span class="wpn-pill"><?php echo esc_html(WP_NERVE_VERSION); ?></span>
                    <a class="button wpn-button" href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve')); ?>"><?php echo esc_html__('Dashboard', 'wp-nerve'); ?></a>
                </div>
            </header>

            <div class="wpn-doc-grid">
                <nav class="wpn-doc-nav" aria-label="<?php echo esc_attr__('Documentation sections', 'wp-nerve'); ?>">
                    <strong><?php echo esc_html__('Contents', 'wp-nerve'); ?></strong>
                    <a href="#quick-start"><?php echo esc_html__('Quick start', 'wp-nerve'); ?></a>
                    <a href="#security"><?php echo esc_html__('Security model', 'wp-nerve'); ?></a>
                    <a href="#risk"><?php echo esc_html__('Risk classes', 'wp-nerve'); ?></a>
                    <a href="#clients"><?php echo esc_html__('Client configuration', 'wp-nerve'); ?></a>
                    <a href="#diagnostics"><?php echo esc_html__('Diagnostics', 'wp-nerve'); ?></a>
                    <a href="#scope"><?php echo esc_html__('Product scope', 'wp-nerve'); ?></a>
                </nav>

                <main class="wpn-doc-content">
                    <section id="quick-start" class="wpn-doc-section">
                        <h2><?php echo esc_html__('Quick start', 'wp-nerve'); ?></h2>
                        <ol>
                            <li><?php echo esc_html__('Open WPNerve → Dashboard.', 'wp-nerve'); ?></li>
                            <li><?php echo esc_html__('Choose the WordPress user the agent should act as and generate a dedicated WPNerve credential.', 'wp-nerve'); ?></li>
                            <li><?php echo esc_html__('Copy the generated MCP client configuration once. The secret is not stored by WPNerve.', 'wp-nerve'); ?></li>
                            <li><?php echo esc_html__('Keep Read and Write enabled for normal operation. Enable Privileged or Destructive only when needed.', 'wp-nerve'); ?></li>
                            <li><?php echo esc_html__('Use Diagnostics before connecting a new client or after a WordPress upgrade.', 'wp-nerve'); ?></li>
                        </ol>
                        <h3><?php echo esc_html__('Endpoint', 'wp-nerve'); ?></h3>
                        <pre><code><?php echo esc_html($endpoint); ?></code></pre>
                    </section>

                    <section id="security" class="wpn-doc-section">
                        <h2><?php echo esc_html__('Security model', 'wp-nerve'); ?></h2>
                        <p><?php echo esc_html__('WPNerve uses WordPress users and capabilities as the primary authorization boundary. The MCP layer adds risk-class policy, idempotency for mutations, short-lived confirmation for high-risk calls, rate limits, audit records, and strict protocol validation.', 'wp-nerve'); ?></p>
                        <ul>
                            <li><?php echo esc_html__('No arbitrary SQL, PHP, shell, WP-CLI, or wp-config.php access.', 'wp-nerve'); ?></li>
                            <li><?php echo esc_html__('Application Passwords and the constrained OAuth flow are supported over HTTPS.', 'wp-nerve'); ?></li>
                            <li><?php echo esc_html__('Credentials and tool arguments are excluded from normal audit records.', 'wp-nerve'); ?></li>
                            <li><?php echo esc_html__('High-risk operations require explicit WordPress administrator approval.', 'wp-nerve'); ?></li>
                        </ul>
                    </section>

                    <section id="risk" class="wpn-doc-section">
                        <h2><?php echo esc_html__('Risk classes', 'wp-nerve'); ?></h2>
                        <p><strong>Read</strong> — <?php echo esc_html__('safe information retrieval.', 'wp-nerve'); ?></p>
                        <p><strong>Write</strong> — <?php echo esc_html__('recoverable mutations such as draft creation or content editing.', 'wp-nerve'); ?></p>
                        <p><strong>Privileged</strong> — <?php echo esc_html__('administrative operations such as users, plugins, and protected option surfaces. Requires confirmation.', 'wp-nerve'); ?></p>
                        <p><strong>Destructive</strong> — <?php echo esc_html__('publish, trash, restore, and other high-impact operations. Requires confirmation.', 'wp-nerve'); ?></p>
                    </section>

                    <section id="clients" class="wpn-doc-section">
                        <h2><?php echo esc_html__('Client configuration', 'wp-nerve'); ?></h2>
                        <p><?php echo esc_html__('WPNerve exposes a Streamable HTTP MCP endpoint and supports the modern 2026-07-28 contract plus bounded compatibility with 2025-11-25 and 2025-06-18.', 'wp-nerve'); ?></p>
                        <pre><code>{
  "mcpServers": {
    "wp-nerve": {
      "type": "http",
      "url": "<?php echo esc_html($endpoint); ?>",
      "headers": {
        "Authorization": "Basic BASE64_USERNAME_COLON_APPLICATION_PASSWORD"
      }
    }
  }
}</code></pre>
                    </section>

                    <section id="diagnostics" class="wpn-doc-section">
                        <h2><?php echo esc_html__('Diagnostics', 'wp-nerve'); ?></h2>
                        <p><?php echo esc_html__('The Diagnostics page compares the 53-ability code contract with WordPress’ live Abilities registry and the current user policy. The operational smoke test exercises discovery, tools/list, reads, writes, confirmation, trash, and restore. HTTP Smoke validates the public HTTPS route with a temporary credential that is revoked automatically.', 'wp-nerve'); ?></p>
                        <p class="wpn-actions">
                            <a class="button wpn-button wpn-button--primary" href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve-diagnostics')); ?>"><?php echo esc_html__('Open Diagnostics', 'wp-nerve'); ?></a>
                            <a class="button wpn-button" href="<?php echo esc_url(admin_url('admin.php?page=wp-nerve-http-smoke')); ?>"><?php echo esc_html__('Open HTTP Smoke', 'wp-nerve'); ?></a>
                        </p>
                    </section>

                    <section id="scope" class="wpn-doc-section">
                        <h2><?php echo esc_html__('Product scope', 'wp-nerve'); ?></h2>
                        <p><?php echo esc_html__('The v1 catalog contains exactly 53 reviewed WordPress abilities. WPNerve is intentionally not a remote shell and does not attempt to expose every possible WordPress or third-party operation. New abilities should be schema-defined, permission-aware, auditable, and assigned a risk class before they become discoverable.', 'wp-nerve'); ?></p>
                    </section>
                </main>
            </div>
        </div>
        <?php
    }
}
