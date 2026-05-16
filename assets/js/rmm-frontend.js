jQuery(document).ready(function($) {
    $(document).on('click', '.rmm-reserve-btn', function(e) {
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

    $(document).on('click', '.rmm-leave-btn', function(e) {
        e.preventDefault();
        const btn = $(this);
        const uuid = btn.data('uuid');
        const postId = btn.data('post-id');

        if (!confirm('¿Seguro que quieres liberar tu slot y dejarlo vacante?')) return;

        btn.prop('disabled', true).text('Saliendo...');

        $.post(rmmFrontend.ajax_url, {
            action: 'liberar_slot',
            nonce: rmmFrontend.nonce,
            uuid: uuid,
            post_id: postId
        }, function(res) {
            if (res.success) {
                location.reload();
            } else {
                alert('Error: ' + res.data);
                btn.prop('disabled', false).text('Desapuntarse');
            }
        });
    });
});
