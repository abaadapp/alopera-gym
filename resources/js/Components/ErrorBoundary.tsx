import { Component, type ErrorInfo, type ReactNode } from 'react';
import { router } from '@inertiajs/react';

/**
 * A screen that fails to draw shows what happened and a way back, instead of a blank page. Moving to another page
 * clears it, so one broken screen does not lock the whole session.
 */
export default class ErrorBoundary extends Component<{ children: ReactNode }, { error: Error | null }> {
    state: { error: Error | null } = { error: null };

    private unlisten?: VoidFunction;

    static getDerivedStateFromError(error: Error) {
        return { error };
    }

    componentDidMount() {
        this.unlisten = router.on('navigate', () => this.state.error && this.setState({ error: null }));
    }

    componentWillUnmount() {
        this.unlisten?.();
    }

    componentDidCatch(error: Error, info: ErrorInfo) {
        console.error(error, info.componentStack);
    }

    render() {
        if (!this.state.error) return this.props.children;

        return (
            <div dir="rtl" className="grid min-h-svh place-items-center bg-[#f7f7f5] p-4">
                <div className="w-full max-w-md rounded-[16px] border border-line bg-white p-6 text-center shadow-sm">
                    <h1 className="text-[17px] font-bold text-ink">تعذّر عرض هذه الصفحة</h1>
                    <p className="mt-2 text-[13px] leading-relaxed text-[#6b7280]">حدث خطأ أثناء رسم الشاشة. بياناتك محفوظة؛ أعد تحميل الصفحة أو ارجع إلى الصفحة السابقة.</p>
                    <div className="mt-4 flex justify-center gap-2">
                        <button type="button" onClick={() => window.location.reload()} className="h-10 rounded-[10px] bg-[#111] px-4 text-[13px] font-medium text-white">إعادة التحميل</button>
                        <button type="button" onClick={() => window.history.back()} className="h-10 rounded-[10px] border border-line px-4 text-[13px] font-medium text-ink">رجوع</button>
                    </div>
                    <pre dir="ltr" className="mt-4 max-h-32 overflow-auto rounded-[8px] bg-[#fafafa] p-2 text-start text-[11px] text-[#9ca3af]">{this.state.error.message}</pre>
                </div>
            </div>
        );
    }
}
