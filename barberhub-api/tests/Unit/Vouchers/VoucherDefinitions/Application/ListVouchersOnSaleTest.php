<?php

declare(strict_types=1);

namespace Tests\Unit\Vouchers\VoucherDefinitions\Application;

use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\OnSaleVouchers;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\PaymentChannel;
use Src\Vouchers\PurchasedVouchers\Application\UseCases\ListVouchersOnSale as ListForCustomer;
use Src\Vouchers\VoucherDefinitions\Application\DescribeCoveredServices;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\ListCoveredServices;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\ListVouchersOnSale;
use Tests\Support\Vouchers\InMemoryCatalogServices;
use Tests\Support\Vouchers\InMemoryVoucherDefinitionRepository;
use Tests\Support\Vouchers\InMemoryVoucherPaymentChannel;
use Tests\Support\Vouchers\InMemoryVoucherSales;
use Tests\Support\Vouchers\VoucherFactory;

/** The reads of the vouchers on sale and of the services a definition covers (RF-27, BO-06). */
final class ListVouchersOnSaleTest extends TestCase
{
    private InMemoryVoucherDefinitionRepository $definitions;

    private DescribeCoveredServices $describe;

    /** A catalog with the fade and the classic cut, in that order of creation. */
    protected function setUp(): void
    {
        $this->definitions = new InMemoryVoucherDefinitionRepository;
        $catalog = new InMemoryCatalogServices;
        $catalog->add(VoucherFactory::FADE, 'Degradado');
        $catalog->add(VoucherFactory::CLASSIC_CUT, 'Corte clásico');
        $this->describe = new DescribeCoveredServices($catalog);
    }

    /** Only the vouchers on sale are listed, each with the names of its services ordered by name, in one page. */
    public function test_it_lists_the_vouchers_on_sale_with_their_named_services(): void
    {
        $onSale = VoucherFactory::definition(id: '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0a31', name: 'Bono B');
        $withdrawn = VoucherFactory::definition(id: '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0a32', name: 'Bono A');
        $withdrawn->withdraw(VoucherFactory::now());
        $this->definitions->save($onSale);
        $this->definitions->save($withdrawn);

        $page = new ListVouchersOnSale($this->definitions, $this->describe)->execute(new PageRequest(1, 10));

        $this->assertSame(1, $page->total);
        $this->assertSame([$onSale->id->value], array_map(fn ($listing): string => $listing->definition->id->value, $page->items));
        $this->assertSame(['Corte clásico', 'Degradado'], array_map(fn ($service): string => $service->name, $page->items[0]->services));
    }

    /** The covered services are read for a definition withdrawn from sale too, and an unknown one is left out. */
    public function test_it_names_the_services_of_a_withdrawn_definition_and_skips_an_unknown_one(): void
    {
        $withdrawn = VoucherFactory::definition();
        $withdrawn->withdraw(VoucherFactory::now());
        $this->definitions->save($withdrawn);

        $services = new ListCoveredServices($this->definitions, $this->describe)->execute([VoucherFactory::DEFINITION, '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0aff']);

        $this->assertSame([VoucherFactory::DEFINITION], array_keys($services));
        $this->assertSame([VoucherFactory::CLASSIC_CUT, VoucherFactory::FADE], array_map(fn ($service): string => $service->id, $services[VoucherFactory::DEFINITION]));
    }

    /** With the vouchers switched off the customer gets an empty page and the shelf is not read. */
    public function test_with_the_vouchers_off_the_customer_page_is_empty_and_the_shelf_is_not_read(): void
    {
        $shelf = new class implements OnSaleVouchers
        {
            public int $reads = 0;

            public function page(PageRequest $page): Page
            {
                $this->reads++;

                return new Page([], 0, $page);
            }
        };

        $off = new ListForCustomer($shelf, new InMemoryVoucherSales(false), new InMemoryVoucherPaymentChannel)->execute(new PageRequest);
        $on = new ListForCustomer($shelf, new InMemoryVoucherSales, new InMemoryVoucherPaymentChannel(new PaymentChannel('paypal', ['tarjeta'])))->execute(new PageRequest);

        $this->assertFalse($off->enabled);
        $this->assertSame([], $off->page->items);
        $this->assertNull($off->channel);
        $this->assertSame(['paypal', ['tarjeta']], [$on->channel?->gateway, $on->channel?->methods]);
        $this->assertTrue($on->enabled);
        $this->assertSame(1, $shelf->reads);
    }
}
