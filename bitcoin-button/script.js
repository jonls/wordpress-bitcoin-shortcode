// Wrap jQuery, undefined
(function($, und) {
	$(function() {
		$('.bitcoin-button').each(function() {
			var $this     = $(this),
			    $wrapper  = $this.wrap('<div class="bitcoin-wrap" />').parent()
			    url       = this.href,
			    address   = $this.data('address'),
			    show_info = $this.data('info'),
			    $counter  = $('<a href="' + url + '" class="bitcoin-counter"></a>'),
			    $bubble   = $('<div class="bitcoin-bubble" style="display:none;"><a href="' + url + '"><img src="http://chart.googleapis.com/chart?chs=200x200&cht=qr&chld=H|0&chl=' + encodeURIComponent(url) + '" class="qr-code" width="200" height="200" alt="" /><span class="address">' + address + '</span></div>');

			$wrapper.append($bubble);
			$wrapper.hover(function() { $bubble.show(); }, function() { $bubble.hide(); });

			if ( show_info && show_info != 'none' ) {
				$.get(bitcoin_button.url, { action: 'bitcoin-address-info', address: address }, function(data) {
				    if ( show_info == 'transaction' && data.transactions !== und ) {
						$counter.text(data.transactions + " tx");
						$counter.insertBefore($bubble);
				    } else if ( show_info == 'balance' && data.balance !== und ) {
						$counter.text((data.balance / 100000000).toFixed(3) + " ฿");
						$counter.insertBefore($bubble);
				    } else if ( show_info == 'received' && data.received !== und ) {
						$counter.text((data.received / 100000000).toFixed(3) + " ฿")
						$counter.insertBefore($bubble);
				    }
				}, 'json');
			}
		});
	});
})(jQuery);
