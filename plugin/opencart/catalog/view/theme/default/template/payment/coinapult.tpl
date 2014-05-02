<div class="buttons">
  <div class="right">
    <input type="submit" value="<?php echo $button_confirm_bitcoin; ?>" id="button-confirm" class="button" />
  </div>
</div>
<script type="text/javascript"><!--
$('#button-confirm').bind('click', function() {
	$.ajax({
		url: 'index.php?route=payment/coinapult/send',
		type: 'post',
		dataType: 'json',
		beforeSend: function() {
			$('#button-confirm').attr('disabled', true);
			$('#button-confirm').before('<div class="attention"><img src="catalog/view/theme/default/image/loading.gif" alt="" /> <?php echo $text_wait; ?></div>');
		},
		complete: function() {
			$('#button-confirm').attr('disabled', false);
			$('.attention').remove();
		},

		success: function(msg) {
			console.log(msg);
			if (msg['error']) {
				alert(msg['error']);
			}
			if (msg['success']) {
				location = msg['success'];
			}
		}
	});
});
//--></script>
