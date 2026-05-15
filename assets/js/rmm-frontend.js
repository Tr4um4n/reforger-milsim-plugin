jQuery(document).ready(function($) {
    $('.rmm-reserve-btn').on('click', function(e) {
        e.preventDefault();
        const btn = $(this);
        const uuid = btn.data('uuid');
        const postId = btn.data('post-id');

        if (!confirm('¿Quieres reclamar este slot para el evento?')) return;

        btn.prop('disabled', true).text('Procesando...');

        $.post(rmmFrontend.ajax_url, {
            action: 'reclamar_slot',
            nonce: rmmFrontend.nonce,
            uuid: uuid,
            post_id: postId
        }, function(res) {
            if (res.success) {
                alert(res.data);
                location.reload();
            } else {
                alert('Error: ' + res.data);
                btn.prop('disabled', false).text('Reclamar Slot');
            }
        });
    });
});
