// Statamic 6.39.0 CP ElementContainer writes reactive width inside ResizeObserver.
// Its first update mounts/resizes the observed tabs in the same delivery cycle.
// Move that write to the next frame; do not suppress errors or wrap global observers.
// Re-evaluate this version-scoped compatibility fix when upgrading Statamic.
Statamic.configuring(({ $app }) => {
    $app.mixin({
        mounted() {
            // Production builds strip __file. Match the ElementContainer contract,
            // then verify its actual observer and cancellable callback after mount.
            if (!Array.isArray(this.$options.emits)
                || this.$options.emits.length !== 1
                || this.$options.emits[0] !== 'resized'
                || !this.$options.watch?.width) return;
            this.$nextTick(() => {
                if (this.$.isUnmounted || !this.observer || !this.throttledCallback?.cancel) return;
                this.observer.disconnect();
                this.throttledCallback.cancel();
                let frame = null;
                let width;
                const callback = entries => {
                    if (!entries[0]) return;
                    width = entries[0].contentRect.width;
                    if (frame !== null) return;
                    frame = requestAnimationFrame(() => {
                        frame = null;
                        if (!this.$.isUnmounted && this.width !== width) this.width = width;
                    });
                };
                callback.cancel = () => { if (frame !== null) cancelAnimationFrame(frame); frame = null; };
                this.throttledCallback = callback;
                this.observer = new ResizeObserver(callback);
                this.observer.observe(this.$el);
            });
        },
    });
});
