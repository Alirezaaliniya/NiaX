jQuery(document).ready(function($) {
    // تابع بروزرسانی قیمت‌های ارز
    function updateCryptoPrices() {
        // یافتن تمام نمونه‌های جدول ارز
        $('.niax-crypto-prices-container').each(function() {
            const container = $(this);
            const table = container.find('tbody');
            let coinsList = '';
            let currency = 'usd';
            
            // استخراج نام‌های ارزها از جدول فعلی
            const coins = [];
            table.find('tr').each(function() {
                const coinName = $(this).find('.niax-crypto-name').text().trim().toLowerCase();
                if (coinName) {
                    coins.push(coinName);
                }
            });
            
            coinsList = coins.join(',');
            
            // ارسال درخواست AJAX
            $.ajax({
                url: niax_crypto_prices_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'niax_update_crypto_prices',
                    nonce: niax_crypto_prices_ajax.nonce,
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
                                $('<td class="niax-crypto-name">').html(
                                    '<img src="' + coin.image + '" alt="' + coin.name + '"> ' + coin.name
                                )
                            );
                            row.append(
                                $('<td class="niax-crypto-price">').text(
                                    parseFloat(coin.current_price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + currency.toUpperCase()
                                )
                            );
                            row.append(
                                $('<td class="niax-crypto-change ' + priceClass + '">').html(
                                    arrow + ' ' + Math.abs(parseFloat(coin.price_change_percentage_24h)).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '%'
                                )
                            );
                            
                            table.append(row);
                        });
                        
                        // بروزرسانی زمان
                        $('#niax-crypto-update-time').text(response.time);
                    }
                }
            });
        });
    }
    
    // بروزرسانی اولیه
    if ($('.niax-crypto-prices-container').length > 0) {
        // تنظیم بروزرسانی دوره‌ای
        setInterval(updateCryptoPrices, niax_crypto_prices_ajax.refresh_interval);
    }
});