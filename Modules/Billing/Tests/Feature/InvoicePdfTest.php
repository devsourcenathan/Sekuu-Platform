<?php

declare(strict_types=1);

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Application\Invoicing\InvoiceFiles;
use Modules\Billing\Application\Invoicing\RenderInvoicePdf;
use Modules\Billing\Domain\Models\Invoice;
use Modules\Billing\Tests\Concerns\BillsAnOrganization;
use Modules\Storage\Domain\Models\Destination;
use Modules\Storage\Domain\Models\StoredFile;
use Tests\TestCase;

/**
 * Le PDF d'une facture, produit une fois et figé.
 *
 * @see docs/04-decisions/adr-0013-invoice-pdf-frozen.md
 */
final class InvoicePdfTest extends TestCase
{
    use BillsAnOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Destination::query()->create([
            'slug' => 'factures',
            'driver' => 'local',
            'config' => ['root' => storage_path('framework/testing/factures')],
            'environment' => app()->environment(),
            'status' => Destination::ACTIVE,
            'is_default' => true,
            'verified_at' => now(),
        ]);

        $this->useFakeProviders();
        $this->signInAsOwner();
        $this->withToken($this->ownerToken);
    }

    public function test_an_issued_invoice_carries_a_real_pdf(): void
    {
        $invoice = $this->issue();

        $this->assertNotNull($invoice->pdf_file_id);
        $this->assertNotNull($invoice->pdf_rendered_at);

        $file = StoredFile::query()->find($invoice->pdf_file_id);

        $this->assertSame(StoredFile::READY, $file->status);
        $this->assertSame('application/pdf', $file->mime_type);
        $this->assertGreaterThan(500, (int) $file->size);
        $this->assertStringContainsString($invoice->number, (string) $file->name);
    }

    /**
     * Dix ans : la durée de conservation des pièces comptables au Cameroun. La
     * rétention est portée par le fichier, pas par une consigne — sans quoi
     * l'obligation ne tiendrait qu'à ce qu'aucune route ne l'efface.
     */
    public function test_the_pdf_cannot_be_deleted_for_ten_years(): void
    {
        $invoice = $this->issue();

        $this->deleteJson("/api/v1/files/{$invoice->pdf_file_id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FILE_RETAINED');

        $this->assertSame(
            now()->addDays(InvoiceFiles::RETENTION_DAYS)->toDateString(),
            StoredFile::query()->find($invoice->pdf_file_id)->retain_until->toDateString(),
        );
    }

    /**
     * Le `503` d'origine a disparu : la route redirige vers une URL signée, et
     * les octets ne traversent pas la plateforme.
     */
    public function test_the_download_route_redirects_instead_of_serving_bytes(): void
    {
        $invoice = $this->issue();

        $response = $this->get("/api/v1/invoices/{$invoice->id}/download");

        $response->assertRedirect();
        $this->assertStringContainsString('object-store', (string) $response->headers->get('Location'));
    }

    /**
     * Le cœur de l'ADR-0013 : le document ne suit pas le code d'aujourd'hui.
     */
    public function test_a_second_download_serves_the_very_same_document(): void
    {
        $invoice = $this->issue();
        $premier = $invoice->pdf_file_id;

        $this->get("/api/v1/invoices/{$invoice->id}/download")->assertRedirect();
        $this->get("/api/v1/invoices/{$invoice->id}/download")->assertRedirect();

        $this->assertSame($premier, $invoice->fresh()->pdf_file_id);
        $this->assertSame(1, StoredFile::query()->where('owner_id', $invoice->id)->count());
    }

    /**
     * Régénérer produit un **nouveau** fichier ; l'ancien demeure, avec sa
     * rétention. Le document envoyé au client reste consultable — écraser
     * l'ancien serait exactement ce que l'ADR refuse.
     */
    public function test_rebuilding_adds_a_document_instead_of_replacing_one(): void
    {
        $invoice = $this->issue();
        $ancien = $invoice->pdf_file_id;

        $nouveau = app(RenderInvoicePdf::class)->rebuild($invoice->fresh());

        $this->assertNotSame($ancien, $nouveau);
        $this->assertSame($nouveau, $invoice->fresh()->pdf_file_id);

        // L'ancien est toujours là, et toujours servable.
        $this->assertSame(StoredFile::READY, StoredFile::query()->find($ancien)->status);
    }

    /**
     * Un utilisateur ne dépose rien sur sa propre facture : un document déposé
     * par un client serait indiscernable de celui que nous avons émis, et
     * porterait la même rétention de dix ans.
     */
    public function test_nobody_can_attach_their_own_document_to_an_invoice(): void
    {
        $invoice = $this->issue();

        $this->postJson('/api/v1/files', [
            'owner_type' => InvoiceFiles::TYPE,
            'owner_id' => (string) $invoice->id,
            'name' => 'ma-facture.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
        ])->assertForbidden();
    }

    /**
     * Rattrape ce que la file a laissé, et les factures antérieures au module
     * de stockage.
     */
    public function test_the_catch_up_command_renders_what_is_missing(): void
    {
        $invoice = $this->issue();
        $invoice->forceFill(['pdf_file_id' => null, 'pdf_rendered_at' => null])->save();

        $this->artisan('billing:invoice-pdf')->assertSuccessful();

        $this->assertNotNull($invoice->fresh()->pdf_file_id);
    }

    private function issue(): Invoice
    {
        $invoice = $this->subscribe('business') ?? throw new \RuntimeException('Aucune facture émise.');

        // `subscribe()` vide les en-têtes : sans cela, tout ce qui suit part
        // sans jeton et répond `401`.
        $this->withToken($this->ownerToken);

        return $invoice;
    }
}
