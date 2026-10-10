    </main>
    <footer class="site-footer">
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}</p>
        @if ($showCredits)
            <p class="credits">{{ __('Built with care') }}</p>
        @endif
    </footer>
    @stack('scripts')
</body>
</html>
