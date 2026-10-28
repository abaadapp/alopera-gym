<?php

namespace Database\Factories;

use App\Modules\Gym\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    private const FIRST = ['مريم', 'فاطمة', 'عائشة', 'سارة', 'نورة', 'هند', 'ريم', 'لمى', 'شهد', 'دانة', 'جود', 'أمل', 'منى', 'رحاب', 'بشرى', 'خلود', 'آمنة', 'زينب', 'أسماء', 'ليلى', 'وعد', 'ميثاء', 'شيخة', 'موزة', 'بدرية', 'غالية', 'رزان', 'هيا', 'نوف', 'العنود'];

    private const LAST = ['البلوشية', 'الحارثية', 'الهنائية', 'السعدية', 'الكندية', 'المعمرية', 'الرواحية', 'البوسعيدية', 'الشكيلية', 'العامرية', 'الزدجالية', 'الفارسية', 'النعمانية', 'الغافرية', 'الحبسية', 'اللواتية', 'المقبالية', 'الجابرية'];

    public function definition(): array
    {
        return [
            'full_name' => fake()->randomElement(self::FIRST).' '.fake()->randomElement(self::LAST),
            'phone' => '9689'.fake()->unique()->numerify('#######'),
            'email' => fake()->boolean(60) ? fake()->unique()->safeEmail() : null,
            'gender' => 'female',
            'date_of_birth' => fake()->dateTimeBetween('-50 years', '-16 years')->format('Y-m-d'),
            'source' => fake()->randomElement(['walk_in', 'website', 'instagram', 'referral']),
            'status' => 'active',
            'whatsapp_opt_in' => true,
            'marketing_opt_in' => fake()->boolean(70),
            'joined_on' => fake()->dateTimeBetween('-10 months', 'now')->format('Y-m-d'),
        ];
    }
}
