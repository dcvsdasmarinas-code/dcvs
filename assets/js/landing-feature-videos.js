(function () {
  var videoLinks = document.querySelectorAll('.process_home_video[data-video="playpause"]');
  if (!videoLinks.length) return;

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function playVideo(video) {
    var playback = video.play();
    if (playback && typeof playback.catch === 'function') {
      playback.catch(function (error) {
        console.error('Landing page feature video could not play:', error);
      });
    }
  }

  videoLinks.forEach(function (link) {
    var video = link.querySelector('video');
    if (!video) return;

    video.muted = true;
    video.playsInline = true;
    link.setAttribute('role', 'button');
    link.setAttribute('tabindex', '0');
    link.setAttribute('aria-pressed', video.paused ? 'false' : 'true');

    video.addEventListener('error', function () {
      console.error('Landing page feature video failed to load:', video.currentSrc || video.src);
    });

    function togglePlayback(event) {
      if (event) {
        event.preventDefault();
        event.stopPropagation();
      }
      if (video.paused) {
        playVideo(video);
      } else {
        video.pause();
      }
      link.setAttribute('aria-pressed', video.paused ? 'false' : 'true');
    }

    link.addEventListener('click', togglePlayback);
    link.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' || event.key === ' ') togglePlayback(event);
    });
    video.addEventListener('play', function () {
      link.setAttribute('aria-pressed', 'true');
    });
    video.addEventListener('pause', function () {
      link.setAttribute('aria-pressed', 'false');
    });
  });

  if (reduceMotion) return;

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var video = entry.target;
        if (entry.isIntersecting) {
          playVideo(video);
        } else {
          video.pause();
        }
      });
    }, { rootMargin: '120px 0px', threshold: 0.15 });

    videoLinks.forEach(function (link) {
      var video = link.querySelector('video');
      if (video) observer.observe(video);
    });
  } else {
    videoLinks.forEach(function (link) {
      var video = link.querySelector('video');
      if (video) playVideo(video);
    });
  }
})();
