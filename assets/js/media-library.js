(function($) {
    $(document).on('click', '.clevers-io-reoptimize-single', function(e) {
        e.preventDefault();

        var $link = $(this);
        var attachmentId = $link.data('id');
        var nonce = $link.data('nonce');

        $link.text(cleversIoMedia.reoptimizing);

        $.post(ajaxurl, {
            action: 'clevers_io_reoptimize_single',
            attachment_id: attachmentId,
            nonce: nonce
        }, function(response) {
            if (response.success) {
                $link.replaceWith(
                    '<span style="color:green;">' +
                    cleversIoMedia.queued +
                    '</span>'
                );
            } else {
                var msg = (response.data && response.data.message)
                    ? response.data.message
                    : cleversIoMedia.errorUnknown;
                $link.replaceWith($('<span>', {
                    text: msg
                }).css('color', 'red'));
            }
        }).fail(function() {
            $link.replaceWith(
                '<span style="color:red;">' + cleversIoMedia.errorConnection + '</span>'
            );
        });
    });
}(jQuery));

(function($) {
    $(document).on('click', '#clevers-io-process-now', function(e) {
        e.preventDefault();

        var $btn = $(this);
        var originalText = $btn.text();
        $btn.prop('disabled', true).text(cleversIoQueue.processing);

        var $status = $('#clevers-io-queue-status');
        $status.text('');

        var runOne = function() {
            $.post(cleversIoQueue.ajaxUrl, {
                action: 'clevers_io_process_batch',
                nonce: cleversIoQueue.nonce
            }, function(response) {
                if (!response || !response.success) {
                    $btn.prop('disabled', false).text(originalText);
                    var msg = (response && response.data && response.data.message)
                        ? response.data.message
                        : cleversIoQueue.errorUnknown;
                    $status.empty().append($('<span>', {
                        text: msg
                    }).css('color', 'red'));
                    return;
                }

                var data = response.data;
                $status.empty().append($('<span>', {
                    text: data.message
                }).css('color', 'green'));

                if (data.remaining > 0 && !data.stopped_early) {
                    runOne();
                } else {
                    $btn.prop('disabled', false).text(originalText);
                }
            }).fail(function() {
                $btn.prop('disabled', false).text(originalText);
                $status.html('<span style="color:red;">' + cleversIoQueue.errorConnection + '</span>');
            });
        };

        runOne();
    });
}(jQuery));
