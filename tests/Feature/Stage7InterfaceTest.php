<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Stage7InterfaceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('sitePages')]
    public function test_pages_keep_labels_and_accessible_references_connected_to_unique_elements(?string $role, string $routeName): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-25 08:00:00', 'Asia/Jerusalem'));
        $manager = User::factory()->manager()->create();
        $worker = User::factory()->create(['birth_date' => '2000-10-25']);
        User::factory()->inactive()->create();

        // Multiple inline edit forms expose duplicate-ID mistakes that an empty
        // dashboard would hide. Today's records also render the worker notices.
        foreach (['מפגש צוות', 'חג שהוזן ידנית'] as $title) {
            CalendarEvent::query()->create([
                'title' => $title,
                'date' => '2026-10-25',
                'kind' => 'event',
                'description' => 'מידע לצוות',
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]);
        }

        if ($role !== null) {
            $this->actingAs($role === 'manager' ? $manager : $worker);
        }

        $response = $this->get(route($routeName));

        $response->assertOk();
        $page = $this->document($response->getContent());
        $this->assertCount(1, $page->query('/html[@lang="he" and @dir="rtl"]'));
        $this->assertCount(1, $page->query('//main[@id="main-content"]'));
        $this->assertAccessibleReferences($page);
    }

    public static function sitePages(): array
    {
        return [
            'guest login' => [null, 'login'],
            'worker calendar' => ['worker', 'worker.home'],
            'worker desks' => ['worker', 'worker.desks.index'],
            'worker profile' => ['worker', 'profile.edit'],
            'manager dashboard' => ['manager', 'admin.users.index'],
            'manager profile' => ['manager', 'profile.edit'],
        ];
    }

    /** Check the rendered page contracts used by labels, navigation and assistive tools. */
    private function assertAccessibleReferences(DOMXPath $page): void
    {
        $elementsById = [];

        foreach ($page->query('//*[@id]') as $element) {
            $id = $element->getAttribute('id');
            $this->assertNotSame('', $id, 'An element ID must not be empty.');
            $this->assertArrayNotHasKey($id, $elementsById, 'Element IDs must be unique: '.$id);
            $elementsById[$id] = $element;
        }

        foreach ($page->query('//label[@for]') as $label) {
            $targetId = $label->getAttribute('for');
            $this->assertArrayHasKey($targetId, $elementsById, 'A label must point to an existing control: '.$targetId);
            $this->assertContains($elementsById[$targetId]->nodeName, ['input', 'select', 'textarea']);
            $this->assertNotSame('', trim($label->textContent), 'Every control label needs readable text.');
        }

        foreach ($page->query('//*[@aria-labelledby or @aria-describedby or @aria-controls]') as $element) {
            foreach (['aria-labelledby', 'aria-describedby', 'aria-controls'] as $attribute) {
                $reference = trim($element->getAttribute($attribute));

                if ($reference === '') {
                    continue;
                }

                foreach (preg_split('/\s+/', $reference) as $targetId) {
                    $this->assertArrayHasKey($targetId, $elementsById, $attribute.' must reference a real element: '.$targetId);
                }
            }
        }

        foreach ($page->query('//a[starts-with(@href,"#")]') as $link) {
            $targetId = substr($link->getAttribute('href'), 1);
            $this->assertArrayHasKey($targetId, $elementsById, 'An in-page link must have a real destination: '.$targetId);
        }
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previousErrorMode = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        return new DOMXPath($document);
    }
}
