<?php
/*
Plugin Name: SSH Tunnel Proxy
Description: Routes HTTP requests through an SSH tunnel server
Version: 2.0
Author: Omri Rotem
*/

if (!defined('ABSPATH')) {
    exit;
}

class SSH_Tunnel_Proxy
{
    // Default configuration
    private $config = array(
        'tunnel_host' => '127.0.0.1',
        'tunnel_port' => 8080,
        'debug_mode' => false
    );

    public function __construct()
    {
        $saved_config = get_option('ssh_tunnel_config', array());
        $this->config = wp_parse_args($saved_config, $this->config);

        // Core functionality
        add_filter('http_request_args', array($this, 'modify_http_request'), 10, 2);

        // Admin interface
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));

        // AJAX handlers for status checks
        add_action('wp_ajax_test_ssh_tunnel', array($this, 'ajax_test_tunnel'));
        add_action('wp_ajax_get_tunnel_status', array($this, 'ajax_get_tunnel_status'));

        add_action('http_api_curl', array($this, 'modify_curl_handle'), 10);
    }

    public function modify_curl_handle($handle)
    {
        $url = curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);

        if ($this->should_route_request($url)) {
            curl_setopt($handle, CURLOPT_PROXY, "socks5h://{$this->config['tunnel_host']}");
            curl_setopt($handle, CURLOPT_PROXYPORT, $this->config['tunnel_port']);
            curl_setopt($handle, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        }
    }
    // Test if tunnel is accessible
    private function test_tunnel_connection()
    {
        $ch = curl_init('https://api.ipify.org?format=json');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => "socks5h://{$this->config['tunnel_host']}",
            CURLOPT_PROXYPORT => $this->config['tunnel_port'],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 5
        ));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($response === false) {
            return array(
                'status' => 'error',
                'message' => "Tunnel connection failed: $error",
                'external_ip' => null
            );
        }

        $data = json_decode($response, true);
        return array(
            'status' => 'success',
            'message' => 'Tunnel is operational',
            'external_ip' => $data['ip'] ?? null
        );
    }

    // Get list of active SSH tunnels
    private function get_active_tunnels()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows command
            exec('netstat -n | findstr ":' . $this->config['tunnel_port'] . '"', $output);
        } else {
            // Linux/Unix command
            exec('lsof -i :' . $this->config['tunnel_port'], $output);
        }

        return $output;
    }

    // AJAX handler for tunnel testing
    public function ajax_test_tunnel()
    {
        check_ajax_referer('ssh_tunnel_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = $this->test_tunnel_connection();
        $tunnels = $this->get_active_tunnels();

        wp_send_json(array(
            'tunnel_status' => $result,
            'active_tunnels' => $tunnels
        ));
    }

    /**
     * Check if the request should be routed through the SSH tunnel
     * 
     * @param string $url The URL of the request
     */
    private function should_route_request($url)
    {
        $route_all = get_option('ssh_tunnel_route_all', false);
        $whitelist_domains = $this->get_whitelist_domains();
        $host = parse_url($url, PHP_URL_HOST);

        return $route_all || in_array($host, $whitelist_domains);
    }

    public function modify_http_request($args, $url)
    {
        $should_route = $this->should_route_request($url);

        if ($should_route) {
            // Check current IP before proxy
            if ($this->config['debug_mode']) {
                $pre_ip = file_get_contents('https://api.ipify.org?format=json');
                error_log("[SSH Tunnel] Pre-proxy IP: " . $pre_ip);
            }

            $args['curl_setopt'][CURLOPT_PROXY] = "socks5h://{$this->config['tunnel_host']}";
            $args['curl_setopt'][CURLOPT_PROXYPORT] = $this->config['tunnel_port'];
            $args['curl_setopt'][CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5_HOSTNAME;
            $args['curl_setopt'][CURLOPT_CAINFO] = '/etc/ssl/cert.pem';
            $args['sslcertificates'] = '/etc/ssl/cert.pem';

            $args['headers']['Host'] = 'test.paytimum.com'; // Ensure Host header matches


            // Force WordPress to use cURL
            $args['transport'] = 'curl';
            $args['curl'] = true;
            $args['blocking'] = true;

            // Verify proxy is used
            if ($this->config['debug_mode']) {
                error_log("[SSH Tunnel] Request URL: " . $url);
                error_log("[SSH Tunnel] CURL options: " . print_r($args, true));
                $args['curl_setopt'][CURLOPT_VERBOSE] = true;
            }
        }

        return $args;
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <!-- Tunnel Status Section -->
            <div class="card">
                <h2>Tunnel Status</h2>
                <div id="tunnel-status-display">
                    <p>Checking tunnel status...</p>
                </div>
                <button type="button" class="button button-primary" id="test-tunnel-button">
                    Test Tunnel Connection
                </button>
            </div>

            <!-- Active Tunnels Section -->
            <div class="card">
                <h2>Active Tunnels</h2>
                <div id="active-tunnels-display">
                    <p>Loading...</p>
                </div>
            </div>

            <!-- Settings Form -->
            <form action="options.php" method="post">
                <?php
                settings_fields('ssh_tunnel_options');
                do_settings_sections('ssh-tunnel-settings');
                submit_button();
                ?>
            </form>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                function updateTunnelStatus() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'test_ssh_tunnel',
                            nonce: '<?php echo wp_create_nonce('ssh_tunnel_nonce'); ?>'
                        },
                        success: function(response) {
                            const status = response.tunnel_status;
                            let statusHtml = '';

                            if (status.status === 'success') {
                                statusHtml = `
                                <div class="notice notice-success">
                                    <p><strong>Status:</strong> ${status.message}</p>
                                    <p><strong>External IP:</strong> ${status.external_ip}</p>
                                </div>
                            `;
                            } else {
                                statusHtml = `
                                <div class="notice notice-error">
                                    <p><strong>Status:</strong> ${status.message}</p>
                                </div>
                            `;
                            }

                            $('#tunnel-status-display').html(statusHtml);

                            // Update active tunnels display
                            let tunnelsHtml = '<pre>' + response.active_tunnels.join('\n') + '</pre>';
                            $('#active-tunnels-display').html(tunnelsHtml);
                        },
                        error: function() {
                            $('#tunnel-status-display').html(
                                '<div class="notice notice-error"><p>Failed to check tunnel status</p></div>'
                            );
                        }
                    });
                }

                // Initial status check
                updateTunnelStatus();

                // Handle manual refresh
                $('#test-tunnel-button').on('click', function() {
                    $(this).prop('disabled', true);
                    updateTunnelStatus();
                    setTimeout(() => $(this).prop('disabled', false), 2000);
                });
            });
        </script>
    <?php
    }

    private function get_whitelist_domains()
    {
        $domains = get_option('ssh_tunnel_whitelist_domains', '');
        if (empty($domains)) {
            return array();
        }
        return array_map('trim', explode("\n", $domains));
    }

    // Settings page methods
    public function add_settings_page()
    {
        add_options_page(
            'SSH Tunnel Settings',
            'SSH Tunnel',
            'manage_options',
            'ssh-tunnel-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings()
    {
        register_setting('ssh_tunnel_options', 'ssh_tunnel_config', array(
            'sanitize_callback' => array($this, 'sanitize_config')
        ));
        register_setting('ssh_tunnel_options', 'ssh_tunnel_whitelist_domains');
        register_setting('ssh_tunnel_options', 'ssh_tunnel_route_all');

        add_settings_section(
            'ssh_tunnel_main',
            'SSH Tunnel Configuration',
            array($this, 'section_callback'),
            'ssh-tunnel-settings'
        );

        // Add settings fields
        add_settings_field(
            'tunnel_config',
            'Tunnel Configuration',
            array($this, 'tunnel_config_callback'),
            'ssh-tunnel-settings',
            'ssh_tunnel_main'
        );

        add_settings_field(
            'route_all',
            'Route All Traffic',
            array($this, 'route_all_callback'),
            'ssh-tunnel-settings',
            'ssh_tunnel_main'
        );

        add_settings_field(
            'whitelist_domains',
            'Whitelisted Domains',
            array($this, 'whitelist_domains_callback'),
            'ssh-tunnel-settings',
            'ssh_tunnel_main'
        );
    }

    public function sanitize_config($input)
    {
        $sanitized = array();
        $sanitized['tunnel_host'] = sanitize_text_field($input['tunnel_host']);
        $sanitized['tunnel_port'] = absint($input['tunnel_port']);
        $sanitized['debug_mode'] = isset($input['debug_mode']);
        return $sanitized;
    }

    public function section_callback()
    {
        echo '<p>Configure your SSH tunnel settings below.</p>';
    }

    public function tunnel_config_callback()
    {
    ?>
        <p>
            <label>
                Tunnel Host:
                <input type="text" name="ssh_tunnel_config[tunnel_host]"
                    value="<?php echo esc_attr($this->config['tunnel_host']); ?>" />
            </label>
        </p>
        <p>
            <label>
                SOCKS Port:
                <input type="number" name="ssh_tunnel_config[tunnel_port]"
                    value="<?php echo esc_attr($this->config['tunnel_port']); ?>" />
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="ssh_tunnel_config[debug_mode]"
                    value="1" <?php checked(true, $this->config['debug_mode']); ?> />
                Enable Debug Logging
            </label>
        </p>
    <?php
    }

    public function route_all_callback()
    {
        $route_all = get_option('ssh_tunnel_route_all', false);
    ?>
        <label>
            <input type="checkbox" name="ssh_tunnel_route_all"
                value="1" <?php checked(1, $route_all); ?> />
            Route all HTTP requests through the tunnel
        </label>
    <?php
    }

    public function whitelist_domains_callback()
    {
        $domains = get_option('ssh_tunnel_whitelist_domains', '');
    ?>
        <textarea name="ssh_tunnel_whitelist_domains" rows="5" cols="50"><?php
                                                                            echo esc_textarea($domains);
                                                                            ?></textarea>
        <p class="description">Enter one domain per line (e.g., api.example.com)<br>
            Only used when "Route All Traffic" is disabled</p>
<?php
    }
}

// Initialize the plugin
new SSH_Tunnel_Proxy();

// Installation hook
register_activation_hook(__FILE__, 'ssh_tunnel_proxy_activate');

function ssh_tunnel_proxy_activate()
{
    // Set default options if they don't exist
    if (!get_option('ssh_tunnel_config')) {
        update_option('ssh_tunnel_config', array(
            'tunnel_host' => '127.0.0.1',
            'tunnel_port' => 8080,
            'debug_mode' => false
        ));
    }
    if (!get_option('ssh_tunnel_whitelist_domains')) {
        update_option('ssh_tunnel_whitelist_domains', '');
    }
    if (!get_option('ssh_tunnel_route_all')) {
        update_option('ssh_tunnel_route_all', false);
    }
}
