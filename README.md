# SSH Tunnel Proxy for WordPress

A WordPress plugin that routes HTTP requests through an SSH tunnel using a SOCKS5 proxy. This is useful when you need to access external APIs or services through a specific IP address or location.

## Requirements

-   WordPress 5.0 or higher
-   PHP 7.0 or higher
-   Active SSH tunnel with SOCKS proxy support

## Quick Start

1. Set up an SSH tunnel on your server:

    ```bash
    ssh -D 8080 -C -q -N username@your-tunnel-server
    ```

2. Install and activate the plugin in WordPress

3. Go to Settings → SSH Tunnel in your WordPress admin panel

4. Configure the basic settings:

    - Tunnel Host: `127.0.0.1` (default)
    - SOCKS Port: `8080` (match your SSH tunnel port)

5. Choose your routing mode:

    - Enable "Route All Traffic" to route all WordPress HTTP requests through the tunnel
    - OR specify individual domains in the "Whitelisted Domains" section

6. Click "Save Changes"

## Configuration Options

### Basic Settings

-   **Tunnel Host**: The local address where your SSH tunnel is listening (usually 127.0.0.1)
-   **SOCKS Port**: The local port your SSH tunnel is using (default: 8080)
-   **Debug Mode**: Enable logging of tunneled requests

### Traffic Routing

-   **Route All Traffic**: Routes all WordPress HTTP requests through the tunnel
-   **Whitelisted Domains**: Specify specific domains to route through the tunnel (one per line)

## Testing the Connection

1. Go to Settings → SSH Tunnel
2. Look at the "Tunnel Status" section
3. Click "Test Tunnel Connection" to verify:
    - Connection status
    - External IP address
    - Active tunnel connections

## Troubleshooting

### Common Issues

1. **Tunnel Connection Failed**

    - Verify your SSH tunnel is running
    - Check if the SOCKS port matches your SSH tunnel
    - Ensure no firewall is blocking the connection

2. **Requests Not Routing**
    - Confirm the domain is in the whitelist (if not using "Route All")
    - Check debug logs if debug mode is enabled
    - Verify WordPress permissions

### Debug Mode

Enable Debug Mode in the plugin settings to log all tunnel activity to the WordPress debug log. This helps identify routing issues and verify which requests are being tunneled.

## Security Notes

-   Keep your SSH tunnel credentials secure
-   Regularly monitor the "Active Tunnels" section
-   Use specific domain whitelisting instead of routing all traffic when possible
-   Consider implementing IP restrictions on your SSH server

## Support

For issues and feature requests, please submit them through the plugin's support channels.

Author: Omri Rotem
Version: 2.0
