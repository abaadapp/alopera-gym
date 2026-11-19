import ClubLayout from '@/Layouts/ClubLayout';
import { PageHeader } from '@/Components/ui';
import { CreatePaymentLink, PaymentLinksLog, type PaymentLinksData } from '@/Components/PaymentLinks';

/** «روابط الدفع» for staff allowed to make them (payments.links), without the Paymob keys page. */
export default function PaymentLinksIndex({ paymentLinks }: { paymentLinks: PaymentLinksData }) {
    return (
        <ClubLayout title="روابط الدفع">
            <PageHeader title="روابط الدفع" subtitle="أنشئ رابط دفع بمبلغ ووصف، وأرسله للعميل بواتساب، وتابع حالته." />
            <div className="space-y-6">
                <CreatePaymentLink data={paymentLinks} />
                <PaymentLinksLog data={paymentLinks} />
            </div>
        </ClubLayout>
    );
}
