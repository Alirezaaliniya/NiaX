<?php
/**
 * Plugin Name: NiaX | نیاکس | نمایش جدول تغییرات ارز
 * Description: نمایش قیمت‌های ارز دیجیتال بصورت دقیقه‌ای با استفاده از شورت‌کد [niax_crypto_prices]
 * Version: 1.0.1
 * Author: Alireza Aliniya
 * Author URI: https://nias.ir
 * Text Domain: niax-crypto-prices
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

// جلوگیری از دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

class Niax_Crypto_Prices_Plugin {
    
    public function __construct() {
        // ثبت شورت‌کد
        add_shortcode('niax_crypto_prices', array($this, 'niax_crypto_prices_shortcode'));
        
        // اضافه کردن فایل‌های CSS و JavaScript
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // تنظیم بروزرسانی خودکار با AJAX
        add_action('wp_ajax_niax_update_crypto_prices', array($this, 'niax_update_crypto_prices'));
        add_action('wp_ajax_nopriv_niax_update_crypto_prices', array($this, 'niax_update_crypto_prices'));
        
        // ایجاد صفحه تنظیمات در بخش مدیریت
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    // اضافه کردن CSS و JavaScript
    public function enqueue_scripts() {
        wp_enqueue_style('niax-crypto-prices-style', plugin_dir_url(__FILE__) . 'assets/css/niax-crypto-prices.css', array(), '1.0.0');
        wp_enqueue_script('niax-crypto-prices-script', plugin_dir_url(__FILE__) . 'assets/js/niax-crypto-prices.js', array('jquery'), '1.0.0', true);
        
        // ارسال متغیرهای مورد نیاز به JavaScript
        wp_localize_script('niax-crypto-prices-script', 'niax_crypto_prices_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('niax_crypto_prices_nonce'),
            'refresh_interval' => get_option('niax_crypto_refresh_interval', 60) * 1000 // تبدیل به میلی‌ثانیه
        ));
    }
    
    // تابع شورت‌کد
    public function niax_crypto_prices_shortcode($atts) {
        // دریافت تنظیمات از پارامترهای شورت‌کد
        $atts = shortcode_atts(array(
            'coins' => 'bitcoin,ethereum,tether,binancecoin,solana',
            'currency' => 'usd',
            'theme' => 'light'
        ), $atts);
        
        // دریافت لیست ارزها
        $coins_list = explode(',', str_replace(' ', '', $atts['coins']));
        
        // دریافت قیمت‌ها
        $prices = $this->get_crypto_prices($coins_list, $atts['currency']);
        
        // شروع محتوا
        ob_start();
        
        echo '<div class="niax-crypto-prices-container ' . esc_attr($atts['theme']) . '">';
        echo '<table class="niax-crypto-prices-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('ارز', 'niax-crypto-prices') . '</th>';
        echo '<th>' . esc_html__('قیمت', 'niax-crypto-prices') . '</th>';
        echo '<th>' . esc_html__('تغییر (۲۴ ساعت)', 'niax-crypto-prices') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        if (!empty($prices) && !isset($prices['error'])) {
            foreach ($prices as $coin) {
                $price_class = ($coin['price_change_24h'] >= 0) ? 'price-up' : 'price-down';
                $arrow = ($coin['price_change_24h'] >= 0) ? '↑' : '↓';
                
                echo '<tr>';
                echo '<td class="niax-crypto-name"><img src="' . esc_url($coin['image']) . '" alt="' . esc_attr($coin['name']) . '"> ' . esc_html($coin['name']) . '</td>';
                echo '<td class="niax-crypto-price">' . esc_html(number_format($coin['current_price'], 2)) . ' ' . strtoupper($atts['currency']) . '</td>';
                echo '<td class="niax-crypto-change ' . $price_class . '">' . $arrow . ' ' . esc_html(abs(number_format($coin['price_change_percentage_24h'], 2))) . '%</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3">' . esc_html__('خطا در دریافت قیمت‌ها. لطفاً بعداً تلاش کنید.', 'niax-crypto-prices') . '</td></tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '<div class="niax-crypto-prices-footer">';
        echo '<span class="niax-crypto-prices-update-time">' . esc_html__('آخرین بروزرسانی: ', 'niax-crypto-prices') . '<span id="niax-crypto-update-time">' . date_i18n('H:i:s') . '</span></span>';
        echo '<span class="niax-crypto-prices-powered-by">' . esc_html__('منبع: CoinGecko', 'niax-crypto-prices') . '</span>';
        echo '</div>';
        echo '</div>';
        
        return ob_get_clean();
    }
    
    // دریافت قیمت‌ها از API
    public function get_crypto_prices($coins, $currency = 'usd') {
        // استفاده از کش وردپرس برای بهینه‌سازی درخواست‌ها
        $cache_key = 'niax_crypto_prices_data_' . md5(implode(',', $coins) . $currency);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        // اگر داده‌ها در کش نبود، از API درخواست می‌کنیم
        $api_url = add_query_arg(array(
            'ids' => implode(',', $coins),
            'vs_currency' => $currency,
            'order' => 'market_cap_desc',
            'per_page' => count($coins),
            'page' => 1,
            'sparkline' => 'false',
            'price_change_percentage' => '24h'
        ), 'https://api.coingecko.com/api/v3/coins/markets');
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array('error' => $response->get_error_message());
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($data) || !is_array($data)) {
            return array('error' => 'خطا در دریافت داده‌ها');
        }
        
        // ذخیره در کش وردپرس (برای 1 دقیقه)
        $cache_time = get_option('niax_crypto_cache_time', 1) * MINUTE_IN_SECONDS;
        set_transient($cache_key, $data, $cache_time);
        
        return $data;
    }
    
    // تابع AJAX برای بروزرسانی قیمت‌ها
    public function niax_update_crypto_prices() {
        check_ajax_referer('niax_crypto_prices_nonce', 'nonce');
        
        $coins = isset($_POST['coins']) ? sanitize_text_field($_POST['coins']) : 'bitcoin,ethereum,tether,binancecoin,solana';
        $currency = isset($_POST['currency']) ? sanitize_text_field($_POST['currency']) : 'usd';
        
        $coins_list = explode(',', str_replace(' ', '', $coins));
        $prices = $this->get_crypto_prices($coins_list, $currency);
        
        wp_send_json(array(
            'success' => true,
            'data' => $prices,
            'time' => date_i18n('H:i:s')
        ));
    }
    
    // اضافه کردن صفحه تنظیمات
    public function add_admin_menu() {
        add_options_page(
            'تنظیمات قیمت ارزهای دیجیتال',
            'قیمت ارزهای دیجیتال',
            'manage_options',
            'niax-crypto-prices-settings',
            array($this, 'settings_page')
        );
    }
    
    // ثبت تنظیمات
    public function register_settings() {
        register_setting('niax_crypto_prices_settings', 'niax_crypto_default_coins');
        register_setting('niax_crypto_prices_settings', 'niax_crypto_default_currency');
        register_setting('niax_crypto_prices_settings', 'niax_crypto_refresh_interval');
        register_setting('niax_crypto_prices_settings', 'niax_crypto_cache_time');
        
        add_settings_section(
            'niax_crypto_prices_settings_section',
            'تنظیمات نمایش قیمت‌های ارز دیجیتال',
            array($this, 'settings_section_callback'),
            'niax-crypto-prices-settings'
        );
        
        add_settings_field(
            'niax_crypto_default_coins',
            'ارزهای پیش‌فرض',
            array($this, 'default_coins_callback'),
            'niax-crypto-prices-settings',
            'niax_crypto_prices_settings_section'
        );
        
        add_settings_field(
            'niax_crypto_default_currency',
            'واحد پول پیش‌فرض',
            array($this, 'default_currency_callback'),
            'niax-crypto-prices-settings',
            'niax_crypto_prices_settings_section'
        );
        
        add_settings_field(
            'niax_crypto_refresh_interval',
            'فاصله بروزرسانی (ثانیه)',
            array($this, 'refresh_interval_callback'),
            'niax-crypto-prices-settings',
            'niax_crypto_prices_settings_section'
        );
        
        add_settings_field(
            'niax_crypto_cache_time',
            'زمان کش (دقیقه)',
            array($this, 'cache_time_callback'),
            'niax-crypto-prices-settings',
            'niax_crypto_prices_settings_section'
        );
    }
    
    // توضیحات بخش تنظیمات
    public function settings_section_callback() {
        echo '<p>تنظیمات افزونه نمایش قیمت ارزهای دیجیتال را در اینجا انجام دهید.</p>';
    }
    
    // فیلدهای تنظیمات
    public function default_coins_callback() {
        $coins = get_option('niax_crypto_default_coins', 'bitcoin,ethereum,tether,binancecoin,solana');
        echo '<input type="text" name="niax_crypto_default_coins" value="' . esc_attr($coins) . '" class="regular-text">';
        echo '<p class="description">نام‌های ارزها را با کاما از هم جدا کنید (مثال: bitcoin,ethereum,tether)</p>';
    }
    
    public function default_currency_callback() {
        $currency = get_option('niax_crypto_default_currency', 'usd');
        echo '<select name="niax_crypto_default_currency">';
        $currencies = array(
            'usd' => 'دلار آمریکا (USD)', 
            'eur' => 'یورو (EUR)', 
            'gbp' => 'پوند (GBP)', 
            'jpy' => 'ین ژاپن (JPY)', 
            'irr' => 'ریال ایران (IRR)',
            'irt' => 'تومان ایران (IRT)'
        );
        foreach ($currencies as $code => $name) {
            echo '<option value="' . esc_attr($code) . '" ' . selected($currency, $code, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select>';
    }
    
    public function refresh_interval_callback() {
        $interval = get_option('niax_crypto_refresh_interval', 60);
        echo '<input type="number" name="niax_crypto_refresh_interval" value="' . esc_attr($interval) . '" min="10" max="600" step="1">';
        echo '<p class="description">فاصله زمانی بروزرسانی خودکار قیمت‌ها بر حسب ثانیه (حداقل 10 ثانیه)</p>';
    }
    
    public function cache_time_callback() {
        $cache_time = get_option('niax_crypto_cache_time', 1);
        echo '<input type="number" name="niax_crypto_cache_time" value="' . esc_attr($cache_time) . '" min="1" max="60" step="1">';
        echo '<p class="description">مدت زمان ذخیره نتایج در کش (به دقیقه)</p>';
    }
    
    // صفحه تنظیمات
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('niax_crypto_prices_settings');
                do_settings_sections('niax-crypto-prices-settings');
                submit_button('ذخیره تنظیمات');
                ?>
            </form>
            
            <div class="niax-crypto-shortcode-help">
                <h2>راهنمای استفاده از شورت‌کد</h2>
                <p>برای نمایش قیمت ارزهای دیجیتال در صفحات سایت خود، از شورت‌کد زیر استفاده کنید:</p>
                <code>[niax_crypto_prices]</code>
                
                <h3>پارامترهای اختیاری:</h3>
                <ul>
                    <li><strong>coins</strong>: لیست ارزهای دیجیتال (با کاما جدا شده)</li>
                    <li><strong>currency</strong>: واحد پول برای نمایش قیمت‌ها</li>
                    <li><strong>theme</strong>: قالب نمایش (light یا dark)</li>
                </ul>
                
                <h3>مثال:</h3>
                <code>[niax_crypto_prices coins="bitcoin,ethereum,cardano" currency="irt" theme="dark"]</code>
            </div>
        </div>
        <?php
    }
}

// شروع افزونه
function run_niax_crypto_prices_plugin() {
    new Niax_Crypto_Prices_Plugin();
}
add_action('plugins_loaded', 'run_niax_crypto_prices_plugin');

// ایجاد فایل‌های مورد نیاز هنگام فعال‌سازی افزونه
function crypto_prices_plugin_activation() {
    // ایجاد پوشه assets اگر وجود ندارد
    if (!file_exists(plugin_dir_path(__FILE__) . 'assets')) {
        mkdir(plugin_dir_path(__FILE__) . 'assets', 0755);
    }
    
    // ایجاد پوشه CSS
    if (!file_exists(plugin_dir_path(__FILE__) . 'assets/css')) {
        mkdir(plugin_dir_path(__FILE__) . 'assets/css', 0755);
    }
    
    // ایجاد فایل CSS
    $css_content = <<<CSS
.crypto-prices-container {
    width: 100%;
    max-width: 800px;
    margin: 20px auto;
    font-family: 'Tahoma', 'IRANSans', sans-serif;
    direction: rtl;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.crypto-prices-container.light {
    background-color: #ffffff;
    color: #333333;
}

.crypto-prices-container.dark {
    background-color: #2d3748;
    color: #f7fafc;
}

.crypto-prices-table {
    width: 100%;
    border-collapse: collapse;
}

.crypto-prices-table th, 
.crypto-prices-table td {
    padding: 12px 15px;
    text-align: right;
}

.crypto-prices-container.light th {
    background-color: #f7f7f7;
    border-bottom: 1px solid #e2e8f0;
}

.crypto-prices-container.dark th {
    background-color: #1a202c;
    border-bottom: 1px solid #4a5568;
}

.crypto-prices-container.light td {
    border-bottom: 1px solid #e2e8f0;
}

.crypto-prices-container.dark td {
    border-bottom: 1px solid #4a5568;
}

.crypto-name {
    display: flex;
    align-items: center;
}

.crypto-name img {
    width: 24px;
    height: 24px;
    margin-left: 10px;
    border-radius: 50%;
}

.crypto-price {
    font-weight: bold;
}

.price-up {
    color: #38a169;
}

.price-down {
    color: #e53e3e;
}

.crypto-prices-footer {
    display: flex;
    justify-content: space-between;
    padding: 10px 15px;
    font-size: 12px;
}

.crypto-prices-container.light .crypto-prices-footer {
    background-color: #f7f7f7;
}

.crypto-prices-container.dark .crypto-prices-footer {
    background-color: #1a202c;
}
CSS;

    file_put_contents(plugin_dir_path(__FILE__) . 'assets/css/crypto-prices.css', $css_content);

    // ایجاد پوشه JS
    if (!file_exists(plugin_dir_path(__FILE__) . 'assets/js')) {
        mkdir(plugin_dir_path(__FILE__) . 'assets/js', 0755);
    }
    
    // ایجاد فایل JavaScript
    $js_content = <<<JS
jQuery(document).ready(function($) {
    // تابع بروزرسانی قیمت‌های ارز
    function updateCryptoPrices() {
        // یافتن تمام نمونه‌های جدول ارز
        $('.crypto-prices-container').each(function() {
            const container = $(this);
            const table = container.find('tbody');
            let coinsList = '';
            let currency = 'usd';
            
            // استخراج نام‌های ارزها از جدول فعلی
            const coins = [];
            table.find('tr').each(function() {
                const coinName = $(this).find('.crypto-name').text().trim().toLowerCase();
                if (coinName) {
                    coins.push(coinName);
                }
            });
            
            coinsList = coins.join(',');
            
            // ارسال درخواست AJAX
            $.ajax({
                url: crypto_prices_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'update_crypto_prices',
                    nonce: crypto_prices_ajax.nonce,
                    coins: coinsList,
                    currency: currency
                },
                success: function(response) {
                    if (response.success && response.data) {
                        // بروزرسانی جدول با داده‌های جدید
                        table.empty();
                        
                        $.each(response.data, function(index, coin) {
                            const priceClass = (coin.price_change_24h >= 0) ? 'price-up' : 'price-down';
                            const arrow = (coin.price_change_24h >= 0) ? '↑' : '↓';
                            
                            const row = $('<tr>');
                            row.append(
                                $('<td class="crypto-name">').html(
                                    '<img src="' + coin.image + '" alt="' + coin.name + '"> ' + coin.name
                                )
                            );
                            row.append(
                                $('<td class="crypto-price">').text(
                                    parseFloat(coin.current_price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + currency.toUpperCase()
                                )
                            );
                            row.append(
                                $('<td class="crypto-change ' + priceClass + '">').html(
                                    arrow + ' ' + Math.abs(parseFloat(coin.price_change_percentage_24h)).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '%'
                                )
                            );
                            
                            table.append(row);
                        });
                        
                        // بروزرسانی زمان
                        $('#crypto-update-time').text(response.time);
                    }
                }
            });
        });
    }
    
    // بروزرسانی اولیه
    if ($('.crypto-prices-container').length > 0) {
        // تنظیم بروزرسانی دوره‌ای
        setInterval(updateCryptoPrices, crypto_prices_ajax.refresh_interval);
    }
});
JS;

    file_put_contents(plugin_dir_path(__FILE__) . 'assets/js/crypto-prices.js', $js_content);
}
register_activation_hook(__FILE__, 'crypto_prices_plugin_activation');
