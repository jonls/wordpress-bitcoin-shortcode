(function ($) {
    $(document).ready(function() {
	$('.bitcoin-button').each(function () {
	    var url = $(this).attr('href');
	    var address = $(this).data('address');
	    var div = $(this).wrap('<div class="bitcoin-div" style="inline-block"/>').parent();
	    var show_info = $(this).data('info');

	    div.append('<div class="bitcoin-bubble" style="display:none;"><div><strong>Bitcoin address</strong></div><a href="'+url+'"><img src="http://chart.googleapis.com/chart?chs=200x200&cht=qr&chld=H|0&chl='+encodeURIComponent(url)+'" width="200" height="200" alt="QR code"/></a><div><a href="'+url+'">'+address+'</a></div></div>');

	    div.hover(function () {
		$(this).find('.bitcoin-bubble').fadeIn(150);
	    }, function () {
		$(this).find('.bitcoin-bubble').fadeOut(150);
	    });

	    if (show_info && show_info != 'none') {
		div.append('<a href="'+url+'" class="bitcoin-counter">&nbsp;</a>');
		$.get('/wp-content/plugins/bitcoin-button/address-info.php', { address: address }, function (data) {
		    var counter = div.find('.bitcoin-counter');
		    if (show_info == 'transaction') {
			counter.text(data.transactions+" tx");
		    } else if (show_info == 'balance') {
			counter.text((data.balance/100000000).toFixed(3)+" ฿");
		    } else if (show_info == 'received') {
			counter.text((data.received/100000000).toFixed(3)+" ฿")
		    }
		}, 'json');
	    }
	});
    });
})(jQuery);
