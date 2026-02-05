/**
 * WooCommerce 1C Integration - Admin Scripts
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Test connection
        $('#wc1c-test-connection').on('click', function() {
            var $button = $(this);
            var $result = $('#wc1c-test-result');

            $button.prop('disabled', true);
            $result.text(wc1cAdmin.strings.testing).removeClass('success error');

            $.ajax({
                url: wc1cAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc1c_test_connection',
                    nonce: wc1cAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.text(response.data).addClass('success');
                    } else {
                        $result.text(wc1cAdmin.strings.error + ': ' + response.data).addClass('error');
                    }
                },
                error: function() {
                    $result.text(wc1cAdmin.strings.error).addClass('error');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

        // Refresh log
        $('#wc1c-refresh-log').on('click', function() {
            location.reload();
        });

        // Clear log
        $('#wc1c-clear-log').on('click', function() {
            if (!confirm(wc1cAdmin.strings.confirm_clear)) {
                return;
            }

            var $button = $(this);
            $button.prop('disabled', true);

            $.ajax({
                url: wc1cAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc1c_clear_log',
                    nonce: wc1cAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#wc1c-log').val('');
                    } else {
                        alert(wc1cAdmin.strings.error + ': ' + response.data);
                    }
                },
                error: function() {
                    alert(wc1cAdmin.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });

        // Manual sync buttons
        $('.wc1c-sync-button').on('click', function() {
            var $button = $(this);
            var syncType = $button.data('sync-type');

            $button.prop('disabled', true).text(wc1cAdmin.strings.syncing);

            $.ajax({
                url: wc1cAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc1c_manual_sync',
                    nonce: wc1cAdmin.nonce,
                    sync_type: syncType
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert(wc1cAdmin.strings.error + ': ' + response.data);
                    }
                },
                error: function() {
                    alert(wc1cAdmin.strings.error);
                },
                complete: function() {
                    $button.prop('disabled', false).text($button.data('original-text'));
                }
            });
        });

        // Store original button text
        $('.wc1c-sync-button').each(function() {
            $(this).data('original-text', $(this).text());
        });

        // Auto-scroll log to bottom
        var $log = $('#wc1c-log');
        if ($log.length) {
            $log.scrollTop($log[0].scrollHeight);
        }
    });

})(jQuery);
