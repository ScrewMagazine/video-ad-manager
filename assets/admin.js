(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var selectButton = document.getElementById('vam-media-select');
    var videoInput = document.getElementById('vam_video_url');
    if (selectButton && videoInput && window.wp && window.wp.media) {
      selectButton.addEventListener('click', function () {
        var frame = window.wp.media({ title: (window.vamI18n && window.vamI18n.selectMp4) || 'Select MP4 commercial', library: { type: 'video' }, button: { text: (window.vamI18n && window.vamI18n.useVideo) || 'Use this video' }, multiple: false });
        frame.on('select', function () {
          var item = frame.state().get('selection').first().toJSON();
          if (item && item.url) {
            videoInput.value = item.url;
            var duration = document.getElementById('vam_duration');
            if (duration && item.meta && item.meta.length) duration.value = Math.round(item.meta.length);
            var width = document.getElementById('vam_width');
            var height = document.getElementById('vam_height');
            if (width && item.width) width.value = item.width;
            if (height && item.height) height.value = item.height;
          }
        });
        frame.open();
      });
    }
    var copyButton = document.getElementById('vam-copy-tag');
    var tagInput = document.getElementById('vam-vast-url');
    if (copyButton && tagInput) {
      copyButton.addEventListener('click', function () {
        tagInput.select();
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(tagInput.value).then(function () { copyButton.textContent = (window.vamI18n && window.vamI18n.copied) || 'Copied!'; }).catch(function () { copyButton.textContent = (window.vamI18n && window.vamI18n.selectCopy) || 'Select and copy URL'; });
        } else {
          copyButton.textContent = (window.vamI18n && window.vamI18n.selectCopy) || 'Select and copy URL';
        }
      });
    }
  });
}());
