<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    public function test_it_removes_executable_html_and_unsafe_urls(): void
    {
        $html = '<p onclick="alert(1)">Safe <strong>text</strong></p>'
            .'<script>alert(2)</script>'
            .'<span style="color:red; background-image:url(javascript:alert(2)); position:fixed">Styled text</span>'
            .'<a href="javascript:alert(3)" target="_blank">Bad link</a>'
            .'<a href="https://example.com" target="_blank">Good link</a>'
            .'<img src="data:text/html,bad" onerror="alert(4)">';

        $clean = HtmlSanitizer::sanitize($html);

        $this->assertStringContainsString('<p>Safe <strong>text</strong></p>', $clean);
        $this->assertStringNotContainsString('script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('data:text', $clean);
        $this->assertStringContainsString('style="color: red"', $clean);
        $this->assertStringNotContainsString('background-image', $clean);
        $this->assertStringNotContainsString('position:', $clean);
        $this->assertStringContainsString('href="https://example.com"', $clean);
        $this->assertStringContainsString('rel="noopener noreferrer"', $clean);
    }

    public function test_it_sanitizes_only_rich_text_builder_fields(): void
    {
        $clean = HtmlSanitizer::sanitizeBuilderJson([
            'content' => [
                'html' => '<img src=x onerror=alert(1)><p>Content</p>',
                'faqAnswer1' => '<script>alert(2)</script><b>Answer</b>',
                'imageUrl' => 'https://example.com/image.jpg',
            ],
        ]);

        $this->assertSame('<img><p>Content</p>', $clean['content']['html']);
        $this->assertSame('<b>Answer</b>', $clean['content']['faqAnswer1']);
        $this->assertSame('https://example.com/image.jpg', $clean['content']['imageUrl']);
    }
}
