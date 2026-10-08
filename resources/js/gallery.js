/*
 * Screenshot viewer of a project sheet.
 *
 * The thumbnails are plain links to the image files, so the page works
 * without this script. With it, a thumbnail opens a modal <dialog> on that
 * image: arrows and swipe move between images, Escape and a click outside
 * the image close it, focus returns to the thumbnail it came from.
 */

const gallery = document.querySelector('[data-gallery]');
const viewer = document.querySelector('[data-gallery-viewer]');

if (gallery !== null && viewer !== null && typeof viewer.showModal === 'function') {
    initViewer(gallery, viewer);
}

function initViewer(gallery, viewer) {
    const links = [...gallery.querySelectorAll('[data-gallery-item]')];
    const items = links.map((link) => {
        const thumbnail = link.querySelector('img');

        return {
            src: link.href,
            alt: thumbnail?.alt ?? '',
            caption: link.dataset.caption ?? '',
            width: thumbnail?.getAttribute('width'),
            height: thumbnail?.getAttribute('height'),
        };
    });

    const image = viewer.querySelector('[data-gallery-image]');
    const caption = viewer.querySelector('[data-gallery-caption]');
    const counter = viewer.querySelector('[data-gallery-counter]');
    const stage = viewer.querySelector('[data-gallery-stage]');
    const strip = viewer.querySelector('[data-gallery-strip]');
    const stripButtons = [...viewer.querySelectorAll('[data-gallery-goto]')];
    const prev = viewer.querySelector('[data-gallery-prev]');
    const next = viewer.querySelector('[data-gallery-next]');

    if (items.length === 0 || image === null || stage === null) {
        console.error('gallery: viewer markup incomplete, thumbnails keep opening the files');

        return;
    }

    // One image: nothing to move between.
    if (items.length === 1) {
        prev.hidden = true;
        next.hidden = true;
        strip.hidden = true;
    }

    let current = 0;
    let opener = null;
    let swipe = null;
    // A swipe on the empty stage ends in a click there, which must not
    // close the viewer it just moved.
    let swiped = false;

    image.addEventListener('load', () => image.classList.remove('is-loading'));
    image.addEventListener('error', () => {
        image.classList.remove('is-loading');
        console.error('gallery: image failed to load', image.src);
    });

    function show(index) {
        current = (index + items.length) % items.length;
        const item = items[current];

        image.classList.add('is-loading');
        image.alt = item.alt;
        setDimension(image, 'width', item.width);
        setDimension(image, 'height', item.height);
        image.src = item.src;

        if (image.complete && image.naturalWidth > 0) {
            image.classList.remove('is-loading');
        }

        caption.textContent = item.caption;
        caption.hidden = item.caption === '';
        counter.textContent = `${current + 1} / ${items.length}`;

        stripButtons.forEach((button, position) => {
            if (position === current) {
                button.setAttribute('aria-current', 'true');
                button.scrollIntoView({ block: 'nearest', inline: 'center' });
            } else {
                button.removeAttribute('aria-current');
            }
        });

        // The neighbours are what the next tap asks for.
        if (items.length > 1) {
            new Image().src = items[(current + 1) % items.length].src;
            new Image().src = items[(current - 1 + items.length) % items.length].src;
        }
    }

    function open(index, from) {
        opener = from;
        show(index);
        document.documentElement.classList.add('overflow-hidden');
        viewer.showModal();
    }

    links.forEach((link, index) => {
        link.addEventListener('click', (event) => {
            // A modified click still means "open the file elsewhere".
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();
            open(index, link);
        });
    });

    prev.addEventListener('click', () => show(current - 1));
    next.addEventListener('click', () => show(current + 1));
    stripButtons.forEach((button, index) => button.addEventListener('click', () => show(index)));
    viewer.querySelector('[data-gallery-close]')?.addEventListener('click', () => viewer.close());

    viewer.addEventListener('close', () => {
        document.documentElement.classList.remove('overflow-hidden');
        opener?.focus();
    });

    viewer.addEventListener('keydown', (event) => {
        if (items.length < 2) {
            return;
        }

        const moves = { ArrowLeft: -1, ArrowRight: 1 };

        if (event.key in moves) {
            event.preventDefault();
            show(current + moves[event.key]);
        } else if (event.key === 'Home') {
            event.preventDefault();
            show(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            show(items.length - 1);
        }
    });

    // The dialog covers the screen: a click that lands on it or on the
    // empty stage, and not on the image or a control, means "close".
    viewer.addEventListener('click', (event) => {
        if (swiped) {
            swiped = false;

            return;
        }

        if (event.target === viewer || event.target === stage) {
            viewer.close();
        }
    });

    stage.addEventListener('pointerdown', (event) => {
        if (event.pointerType !== 'mouse') {
            swipe = { x: event.clientX, y: event.clientY };
        }
    });

    stage.addEventListener('pointerup', (event) => {
        if (swipe === null || items.length < 2) {
            swipe = null;

            return;
        }

        const dx = event.clientX - swipe.x;
        const dy = event.clientY - swipe.y;
        swipe = null;

        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
            swiped = true;
            show(current + (dx < 0 ? 1 : -1));
        }
    });

    stage.addEventListener('pointercancel', () => {
        swipe = null;
    });
}

function setDimension(element, name, value) {
    if (value === null || value === undefined) {
        element.removeAttribute(name);
    } else {
        element.setAttribute(name, value);
    }
}
