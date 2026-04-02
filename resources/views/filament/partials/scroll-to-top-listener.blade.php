<script>
    if (! window.__filamentScrollToTopListenerRegistered) {
        window.__filamentScrollToTopListenerRegistered = true;

        window.addEventListener('scroll-to-top', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth',
            });
        });
    }
</script>
