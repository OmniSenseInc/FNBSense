<x-filament-widgets::widget>
    <x-filament::section
        heading="Produk terlaris"
        description="Diurutkan berdasarkan jumlah item terjual pada periode terpilih."
    >
        @php($products = $this->products())

        @if (count($products) === 0)
            <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                Belum ada data produk untuk periode ini.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-3 py-3">#</th>
                            <th class="px-3 py-3">Produk</th>
                            <th class="px-3 py-3 text-right">Terjual</th>
                            <th class="px-3 py-3 text-right">Omzet produk</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $index => $product)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="px-3 py-3 font-semibold text-gray-400">{{ $index + 1 }}</td>
                                <td class="px-3 py-3">
                                    <div class="font-semibold text-gray-950 dark:text-white">
                                        {{ $product['product_name'] ?: 'Produk tanpa snapshot nama' }}
                                    </div>
                                    <div class="font-mono text-xs text-gray-400">{{ $product['product_id'] }}</div>
                                </td>
                                <td class="px-3 py-3 text-right font-semibold">
                                    {{ number_format((int) $product['qty'], 0, ',', '.') }}
                                </td>
                                <td class="px-3 py-3 text-right font-semibold text-emerald-600 dark:text-emerald-400">
                                    Rp{{ number_format((int) $product['revenue'], 0, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
