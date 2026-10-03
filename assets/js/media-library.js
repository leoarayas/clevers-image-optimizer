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
        var $progressWrapper = $('#clevers-io-queue-progress-wrapper');
        var $progress = $('#clevers-io-queue-progress');
        var $progressValue = $('#clevers-io-queue-progress-value');
        var initialCount = Number(cleversIoQueue.initialCount) || 0;
        $status.text('');
        $progressWrapper.prop('hidden', false);
        $progress.val(0);
        $progressValue.text('0%');

        var updateProgress = function(remaining) {
            var completed = Math.max(0, initialCount - remaining);
            var percentage = initialCount > 0
                ? Math.min(100, Math.round((completed / initialCount) * 100))
                : 100;

            $progress.val(percentage);
            $progressValue.text(percentage + '%');
        };

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
                updateProgress(Number(data.remaining) || 0);
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
