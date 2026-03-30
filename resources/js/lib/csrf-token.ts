export function getCsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}
