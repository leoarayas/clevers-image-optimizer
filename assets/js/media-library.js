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
                $link.replaceWith('<span style="color:red;">' + msg + '</span>');
            }
        }).fail(function() {
            $link.replaceWith(
                '<span style="color:red;">' + cleversIoMedia.errorConnection + '</span>'
            );
        });
    });
}(jQuery));
