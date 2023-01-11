(function ($) {
    $(document).ready(function () {
        $('.cart input[name=variation_id]').change(function () {
            if ($('.single_add_to_cart_button').hasClass('wc-variation-selection-needed')) {
                $("#wcolp-container").html('');
            }
            const variation_id = $(this).val();
            if (variation_id && WCOLP.hasOwnProperty(variation_id)) {
                $("#wcolp-container").html((WCOLP[variation_id]));
            }
        });
    });
})(jQuery);
