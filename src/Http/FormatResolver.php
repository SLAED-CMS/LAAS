<?php
declare(strict_types=1);

namespace Laas\Http;

final class FormatResolver
{
    public function resolve(Request $req): string
    {
        $format = $req->query('format');
        if (is_string($format)) {
            $format = strtolower($format);
        } else {
            $format = '';
        }

        if (HeadlessMode::isEnabled()) {
            if ($format === 'json') {
                return 'json';
            }
            if ($format === 'html') {
                return HeadlessMode::isHtmlAllowed($req) ? 'html' : 'json';
            }
            if (HeadlessMode::isHtmlRequested($req)) {
                return HeadlessMode::isHtmlAllowed($req) ? 'html' : 'json';
            }
            return 'json';
        }

        if ($format === 'json') {
            return 'json';
        }
        if ($format === 'html') {
            return 'html';
        }

        $accept = $req->getHeader('accept') ?? '';
        if (stripos($accept, 'application/json') !== false) {
            return 'json';
        }

        return 'html';
    }
}
