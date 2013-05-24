/**
 * Sitemap proxy functions to dynatree
 */


(function($, window) {

  var methods = {

    private:  {

    	enableEditing: function($aggregator, options) {
			$aggregator.find('a[data-inline-command=options-tile]').not('.event-bound').on('click', function() {
				var agiID = $(this).closest('div.ccm-aggregator-item').attr('data-aggregator-item-id');
				var href = CCM_TOOLS_PATH + '/aggregator/edit_template?agiID=' + agiID;
				jQuery.fn.dialog.open({
					modal: true,
					href: href,
					width: '400',
					height: '150',
					title: options.titleEditTemplate
				});
			}).addClass('event-bound');

			var $itemElements = $($aggregator.packery('getItemElements')).not('.event-bound');
			$itemElements.draggable({
				'handle': 'a[data-inline-command=move-tile]'
			});

			$aggregator.packery('on', 'dragItemPositioned', function(pkr, item) {
				var data = [
					{'name': 'task', 'value': 'update_display_order'},
					{'name': 'agID', 'value': options.agID},
					{'name': 'editToken', 'value': options.editToken}
				];
				var items = [];
				var elements = pkr.getItemElements();
				for (i = 0; i < elements.length; i++) {
					var $obj = $(elements[i]);
					data.push({'name': 'agiID[' + $obj.attr('data-aggregator-item-batch-timestamp') + '][]', 'value': $obj.attr('data-aggregator-item-id')});
				}

				console.log(data);
				$.ajax({
					type: 'post',
					url: CCM_TOOLS_PATH + '/aggregator/update',
					data: data
				});
			});

			$aggregator.packery( 'bindUIDraggableEvents', $itemElements );
			$itemElements.resizable({
				handles: 'se',
				grid: [options.columnWidth, options.rowHeight],
				resize: function(e, ui) {
					var $tile = ui.element,
					wx = parseInt($tile.css('width')),
					hx = parseInt($tile.css('height')),
					w = Math.floor(wx / options.columnWidth),
					h = Math.floor(hx / options.rowHeight);

					$aggregator.packery('layout');

					$.ajax({
						type: 'post',
						url: CCM_TOOLS_PATH + '/aggregator/update',
						data: {
							'task': 'resize',
							'agID': options.agID,
							'agiID': $tile.attr('data-aggregator-item-id'),
							'agiSlotWidth': w,
							'agiSlotHeight': h,
							'editToken': options.editToken
						}
					});
				}
			});
			$itemElements.not('.event-bound').addClass('event-bound');
    	}

    },

    setupTemplateForm: function(options) {
    	return this.each(function() {
    		var $form = $(this);
			$form.on('submit', function() {
				jQuery.fn.dialog.showLoader();
				$.ajax({
					type: 'POST',
					data: {
						agtID: $form.find('select[name=agtID]').val(),
						agiID: options.agiID,
						token: options.updateToken
					},
					url: CCM_TOOLS_PATH + '/aggregator/edit_template',
					success: function(r) {
						jQuery.fn.dialog.hideLoader();
						// load the newly rendered HTML into the old aggregator item.
						$('[data-aggregator-item-id=' + options.agiID + ']').find('div.ccm-aggregator-item-inner-render').html(r);
						jQuery.fn.dialog.closeTop();
					}
				});
				return false;
			});
    	});
    },

	init: function(options) {

		var options = $.extend({
			totalPages: 0,
			columnWidth: 120,
			rowHeight: 120,
			showTileComamnds: 0
		}, options);

		return this.each(function() {
			var $aggregator = $(this);
			$(this).data('options', options);
			var $loadButton = $aggregator.parent().find('button[data-aggregator-button=aggregator-load-more-items]');
			if (options.totalPages == 1) {
				$loadButton.hide();
			}
			$aggregator.packery({
				columnWidth: options.columnWidth,
				rowHeight: options.rowHeight
			});
			$aggregator.css('opacity', 1);
			$loadButton.on('click', function() {
				page = parseInt($aggregator.attr('data-aggregator-current-page')),
					newPage = page + 1;

				$loadButton.prop('disabled', true);

				$.ajax({
					type: 'post',
					url: CCM_TOOLS_PATH + '/aggregator/load_more',
					data: {
						'task': 'get_aggregator_items',
						'agID': options.agID,
						'page': newPage,
						'loadToken': options.loadToken,
						'editToken': options.editToken,
						'showTileCommands': options.showTileCommands
					},
				
					success: function(r) {
						var elements = ($('<div />').append(r).find('>div'));
						$.each(elements, function(i, obj) {
							$aggregator.append(obj);
						});
					
						$aggregator.packery('appended', elements);
						if (newPage == options.totalPages) {
							$loadButton.hide();
						} else {
							$loadButton.prop('disabled', false);
							$aggregator.attr('data-aggregator-current-page', newPage);
						}
						methods.private.enableEditing($aggregator, options);
					}
				});
			});

			if (options.showTileCommands) {
				methods.private.enableEditing($aggregator, options);
			}
		});	
    }
  }

  $.fn.ccmaggregator = function(method) {

    if ( methods[method] ) {
      return methods[method].apply( this, Array.prototype.slice.call( arguments, 1 ));
    } else if ( typeof method === 'object' || ! method ) {
      return methods.init.apply( this, arguments );
    } else {
      $.error( 'Method ' +  method + ' does not exist on jQuery.ccmaggregator' );
    }   

  };
})(jQuery, window);