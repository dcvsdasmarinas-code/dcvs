document.addEventListener('DOMContentLoaded', function () {
  var knowledgePage = document.body.classList.contains('knowledge-hub-page');
  var initiativesPage = document.body.classList.contains('phil-initiatives-page');
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var revealTargets = document.querySelectorAll(
    '.knowledge-hero .prose-content, .knowledge-copy-sticky, .knowledge-stack .stack-item, .knowledge-outro h2, .initiatives-heading h1, .premium-card'
  );

  function enableReveals() {
    if (document.body.classList.contains('insights-motion-ready')) return;
    document.body.classList.add('insights-motion-ready');
    revealTargets.forEach(function (target) { target.classList.add('insights-reveal'); });
    if (reduceMotion || !('IntersectionObserver' in window)) {
      revealTargets.forEach(function (target) { target.classList.add('is-visible'); });
      return;
    }
    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    revealTargets.forEach(function (target) { revealObserver.observe(target); });
  }

  if (document.visibilityState === 'visible') {
    enableReveals();
  } else {
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'visible') enableReveals();
    });
  }

  if (knowledgePage) {
    var items = Array.prototype.slice.call(document.querySelectorAll('.knowledge-stack .stack-item'));
    var title = document.getElementById('stickyTitle');
    var subtitle = document.getElementById('stickySubtitle');
    var description = document.getElementById('stickyDescription');
    var number = document.getElementById('stickyNumber');
    var content = document.getElementById('stickyContent');
    var activeItem = null;

    function activateItem(item) {
      if (!item || activeItem === item) return;
      var shouldFade = !!(activeItem && content && !reduceMotion);
      activeItem = item;
      items.forEach(function (candidate) {
        candidate.classList.toggle('is-active', candidate === item);
      });
      if (shouldFade) {
        content.classList.remove('is-changing');
        void content.offsetWidth;
        content.classList.add('is-changing');
      }
      if (title) title.innerHTML = item.dataset.title || '';
      if (subtitle) subtitle.textContent = item.dataset.subtitle || '';
      if (description) description.textContent = item.dataset.description || '';
      if (number) number.textContent = item.dataset.number || '';
    }

    items.forEach(function (item) {
      item.addEventListener('click', function (event) {
        event.preventDefault();
        activateItem(item);
      });
      item.addEventListener('focus', function () { activateItem(item); });
    });

    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) activateItem(entry.target);
        });
      }, { rootMargin: '-35% 0px -35% 0px', threshold: 0 });
      items.forEach(function (item) { observer.observe(item); });
    }

    activateItem(items[0]);
  }

  if (initiativesPage) {
    var container = document.querySelector('.mySwiper');
    var pagination = document.querySelector('.initiatives-pagination .swiper-pagination');
    if (!container) return;

    if (typeof window.Swiper === 'function') {
      new window.Swiper(container, {
        slidesPerView: 1.12,
        spaceBetween: 16,
        speed: reduceMotion ? 0 : 550,
        watchOverflow: true,
        pagination: pagination ? { el: pagination, type: 'progressbar' } : undefined,
        breakpoints: {
          768: { slidesPerView: 3, spaceBetween: 24 },
          1200: { slidesPerView: 4, spaceBetween: 24 }
        }
      });
    } else {
      var wrapper = container.querySelector('.swiper-wrapper');
      if (wrapper) {
        wrapper.style.overflowX = 'auto';
        wrapper.style.scrollSnapType = 'x mandatory';
        wrapper.querySelectorAll('.swiper-slide').forEach(function (slide) {
          slide.style.scrollSnapAlign = 'start';
        });
      }
    }
  }
});
