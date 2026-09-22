{{-- The schema.org description of the page, as a data block.

     This is the one script element the public pages carry, and it runs
     nothing: a type other than JavaScript is never executed, which is
     also why the policy that forbids inline script does not have to be
     relaxed for it. The rule it does not break is the one that matters -
     no behaviour on a public page depends on a browser running code.

     Encoded with the tag characters escaped: the values come from
     announcements written by third parties, and a title holding a
     closing tag would otherwise end the block early. --}}
<script type="application/ld+json">{!! json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
