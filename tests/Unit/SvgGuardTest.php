<?php

declare(strict_types=1);

use App\Services\SvgGuard;

function svg_doc(string $body, string $attrs = ''): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10"' . $attrs . '>'
        . $body . '</svg>';
}

test('svg: ordinary drawings pass, including editor exports', function () {
    assert_same(null, SvgGuard::problem(svg_doc('<circle cx="5" cy="5" r="4" fill="#09f"/>')));
    assert_same(null, SvgGuard::problem(svg_doc('<style>.a{fill:red}</style><rect class="a" width="5" height="5"/>')));
    assert_same(null, SvgGuard::problem(svg_doc('<a href="https://example.com"><text x="1" y="5">link</text></a>')));
    assert_same(null, SvgGuard::problem(svg_doc('<image href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/>')));
    assert_same(null, SvgGuard::problem(svg_doc('<circle r="1"><animate attributeName="r" values="1;3;1" dur="1s" repeatCount="indefinite"/></circle>')));
    // Illustrator: an internal DTD subset with namespace entities.
    $ai = '<?xml version="1.0" encoding="utf-8"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd" ['
        . '<!ENTITY ns_svg "http://www.w3.org/2000/svg"><!ENTITY ns_xlink "http://www.w3.org/1999/xlink">]>'
        . '<svg version="1.1" xmlns="&ns_svg;" xmlns:xlink="&ns_xlink;" width="10" height="10"><path d="M0 0L10 10"/></svg>';
    assert_same(null, SvgGuard::problem($ai));
});

test('svg: anything that can run script or load a document is refused', function () {
    $bad = [
        'script element'      => svg_doc('<script>alert(1)</script>'),
        'uppercase SCRIPT'    => svg_doc('<SCRIPT xmlns="http://www.w3.org/2000/svg">alert(1)</SCRIPT>'),
        'CDATA script'        => svg_doc('<script><![CDATA[alert(1)]]></script>'),
        'onload on root'      => svg_doc('<circle r="1"/>', ' onload="alert(1)"'),
        'onclick'             => svg_doc('<rect width="1" height="1" onclick="alert(1)"/>'),
        'javascript href'     => svg_doc('<a href="javascript:alert(1)"><text>x</text></a>'),
        'xlink javascript'    => svg_doc('<a xlink:href=" java&#x09;script:alert(1)"><text>x</text></a>'),
        'data html href'      => svg_doc('<a href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;"><text>x</text></a>'),
        'animated href'       => svg_doc('<a><animate attributeName="href" values="javascript:alert(1)"/><text>x</text></a>'),
        'set xlink:href'      => svg_doc('<a><set attributeName="xlink:href" to="https://example.com"/><text>x</text></a>'),
        'foreignObject'       => svg_doc('<foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><iframe src="https://example.com"/></body></foreignObject>'),
        'iframe'              => svg_doc('<iframe xmlns="http://www.w3.org/1999/xhtml" src="https://example.com"/>'),
        'style javascript'    => svg_doc('<style>@import url(https://example.com/x.css);</style>'),
        'inline style'        => svg_doc('<rect style="background:url(javascript:alert(1))" width="1" height="1"/>'),
        'entity hides script' => '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "&#60;script&#62;alert(1)&#60;/script&#62;">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>',
        'external entity'     => '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>',
        'parameter entity'    => '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY % p "x">]><svg xmlns="http://www.w3.org/2000/svg"/>',
        'xml-stylesheet PI'   => '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="https://example.com/x.xsl"?><svg xmlns="http://www.w3.org/2000/svg"/>',
        'not an svg'          => '<html><script>alert(1)</script></html>',
        'not xml'             => '<svg><script>alert(1)',
    ];
    foreach ($bad as $label => $svg) {
        assert_true(SvgGuard::problem($svg) !== null, "{$label} was accepted");
    }
});
