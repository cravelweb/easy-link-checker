jQuery(document).ready(function ($) {
  let postIdsToCheck = [];

  // 「すべて選択」チェックボックスの処理
  $('#the-list').on('change', 'input[type="checkbox"][name="mlc_post_ids[]"]', function () {
    let allChecked = $('input[name="mlc_post_ids[]"]:not(:checked)').length === 0;
    $('th.manage-column.column-cb.check-column input[type="checkbox"]').prop('checked', allChecked);
  });

  $('th.manage-column.column-cb.check-column input[type="checkbox"]').on('change', function () {
    let checked = $(this).is(':checked');
    $('input[name="mlc_post_ids[]"]').prop('checked', checked);
  });

  // チェックオプションの変更時の処理
  // Check LinksとCheck Imagesは両方解除できない
  $('#check_links').on('change', function () {
    if (!$(this).is(':checked') && !$('#check_images').is(':checked')) {
      $(this).prop('checked', true);
    }
  });

  // Check Internal Links OnlyがチェックされたらCheck Linksもチェックされる
  $('#check_internal_only').on('change', function () {
    if ($(this).is(':checked')) {
      $('#check_links').prop('checked', true);
    }
  });

  // 「Check Links」ボタンがクリックされたときの処理
  $('#mlc-check-links').on('click', function () {
    postIdsToCheck = [];
    $('input[name="mlc_post_ids[]"]:checked').each(function () {
      postIdsToCheck.push($(this).val());
      // チェック中のステータスを表示
      const row = $(this).closest('tr');
      row.find('.link-check-status').html('<span class="mlc-await">' + mlc_vars.i18n.checking + '</span>');
    });

    if (postIdsToCheck.length > 0) {
      console.log(postIdsToCheck);

      batchCheckLinks();
    } else {
      alert(mlc_vars.i18n.please_select);
    }
  });

  function batchCheckLinks() {
    if (postIdsToCheck.length === 0) {
      $('#mlc-spinner').hide();
      return;
    }

    const batchSize = 5;
    const postIdsBatch = postIdsToCheck.splice(0, batchSize);
    const checkLinks = $('#check_links').is(':checked');
    const checkInternalOnly = $('#check_internal_only').is(':checked');
    const checkImages = $('#check_images').is(':checked');

    $.ajax({
      url: mlc_vars.ajax_url,
      method: 'POST',
      data: {
        action: 'mlc_check_links',
        post_ids: postIdsBatch,
        check_links: checkLinks,
        check_internal_only: checkInternalOnly,
        check_images: checkImages
      },
      beforeSend: function () {
        $('#mlc-spinner').show();
      },
      success: function (response) {
        const results = JSON.parse(response);

        console.log(results);

        results.forEach(function (result) {
          const row = $("#mlc-posts-list tr[data-post-id='" + result.post_id + "']");

          // リンクのチェック結果
          if (result.links && result.links.length === 0) {
            row.find('.link-check-status').html('<div class="mlc-checked-item"><span class="mlc-label mlc-label-nolinks">' + mlc_vars.i18n.no_links + '</span></div>');
          } else {
            let linksHtml = '';

            // リンクのステータスをレンダリング

            // リンク切れ警告メッセージのレンダリング
            if (result.broken_links && result.broken_links.length > 0) {
              result.broken_links.forEach(function (link) {
                linksHtml += '<a href="' + link + '" target="_blank">' + link + '</a><br>';
              });
              row.find('.link-check-status').html('<div class="mlc-checked-item"><span class="mlc-label mlc-label-broken">' + mlc_vars.i18n.broken_links + '</span><br>' + linksHtml + '</div>');
            } else if (result.skipped_links && result.skipped_links.length > 0) {
              // スキップされたリンクのレンダリング
              result.skipped_links.forEach(function (link) {
                linksHtml += '<a href="' + link + '" target="_blank">' + link + '</a><br>';
              });
              row.find('.link-check-status').html('<div class="mlc-checked-item"><span class="mlc-label mlc-label-skipped">' + mlc_vars.i18n.skipped + '</span><br>' + linksHtml + '</div>');
            } else if (result.valid_links && result.valid_links.length > 0) {
              // 有効なリンクのレンダリング
              result.valid_links.forEach(function (link) {
                linksHtml += '<a href="' + link + '" target="_blank">' + link + '</a><br>';
              });
              row.find('.link-check-status').html('<div class="mlc-checked-item"><span class="mlc-label mlc-label-ok">' + mlc_vars.i18n.valid_links + '</span><br>' + linksHtml + '</div>');
            }

            // リダイレクト警告メッセージのレンダリング
            if (result.redirected_links && result.redirected_links.length > 0) {
              let redirectHtml = '';
              result.redirected_links.forEach(function (redirect) {
                redirectHtml += '<a href="' + redirect.url + '" target="_blank">' + redirect.url + '</a><br>';
                redirectHtml += '<span class="mlc-label mlc-label-warning">' + redirect.message + '</span><br>';
              });
              row.find('.link-check-status').append('<div class="mlc-checked-item"><span class="mlc-label mlc-label-warning">' + mlc_vars.i18n.redirected_links + '</span><br>' + redirectHtml + '</div>');
            }
          }

          // 画像のチェック結果
          if (result.images && result.images.length > 0) {
            let imagesHtml = '';
            if (result.broken_images && result.broken_images.length > 0) {
              result.broken_images.forEach(function (image) {
                imagesHtml += '<a href="' + image + '" target="_blank">' + image + '</a><br>';
              });
              row.find('.link-check-status').append('<div class="mlc-checked-item"><span class="mlc-label mlc-label-broken">' + mlc_vars.i18n.broken_images + '</span><br>' + imagesHtml + '</div>');
            } else if (result.valid_images && result.valid_images.length > 0) {
              result.valid_images.forEach(function (image) {
                imagesHtml += '<a href="' + image + '" target="_blank">' + image + '</a><br>';
              });
              row.find('.link-check-status').append('<div class="mlc-checked-item"><span class="mlc-label mlc-label-ok">' + mlc_vars.i18n.valid_images + '</span><br>' + imagesHtml + '</div>');
            }
          }
        });
      },
      complete: function () {
        batchCheckLinks();
      },
      error: function () {
        $('#mlc-spinner').hide();
        alert(mlc_vars.i18n.failed_to_check);
      }
    });
  }
});
