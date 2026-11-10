---
id: 7c106000-0000-4000-8000-000000000001
blueprint: page
title: 'Demonstracja Core'
document_language: pl
template: smk/core/pages/page
page_builder:
  -
    id: demo-hero
    type: hero
    enabled: true
    anchor: wprowadzenie
    theme: surface
    width: wide
    spacing: spacious
    heading: 'Przestrzeń dla Twoich treści.'
    eyebrow: 'SMK / STRONA DEMONSTRACYJNA'
    lead: 'Jeden spójny układ. Trzy sekcje, które możesz edytować, porządkować i pokazywać na własnych zasadach.'
    image:
      - 'assets::demo/core-composition.png'
    alt_override: 'Abstrakcyjna kompozycja: gliniany łuk, koło i jasne płaszczyzny.'
    caption: 'Neutralna kompozycja demonstracyjna.'
    credit: 'SMK · lokalny fixture, 2026'
    links:
      -
        label: 'Odkryj układ'
        destination_type: url
        url: '#tresc'
        new_window: false
  -
    id: demo-text
    type: rich_text
    enabled: true
    anchor: tresc
    theme: surface
    width: reading
    spacing: normal
    heading: 'Prosta forma. Swoboda treści.'
    body:
      -
        type: paragraph
        content:
          -
            type: text
            text: 'To jest strona demonstracyjna neutralnego Core. Pokazuje układ, czytelną typografię i sposób prowadzenia odbiorcy od nagłówka do działania. Nie przedstawia oferty żadnej firmy.'
      -
        type: heading
        attrs:
          level: 3
        content:
          -
            type: text
            text: 'Treść w Twojej kolejności'
      -
        type: paragraph
        content:
          -
            type: text
            text: 'Sekcje zachowują zapisaną kolejność. Możesz zmienić tekst, wyłączyć fragment lub wybrać jeden z zatwierdzonych motywów — bez pisania kodu.'
      -
        type: bulletList
        content:
          -
            type: listItem
            content:
              -
                type: paragraph
                content:
                  -
                    type: text
                    text: 'Hero otwiera stronę jednym nagłówkiem H1.'
          -
            type: listItem
            content:
              -
                type: paragraph
                content:
                  -
                    type: text
                    text: 'Rich Text porządkuje akapity, listy i linki.'
          -
            type: listItem
            content:
              -
                type: paragraph
                content:
                  -
                    type: text
                    text: 'CTA domyka opowieść czytelnym działaniem.'
      -
        type: paragraph
        content:
          -
            type: text
            text: 'Zobacz ostatnią sekcję'
            marks:
              -
                type: link
                attrs:
                  href: '#dalej'
                  target: null
                  rel: null
  -
    id: demo-cta
    type: cta
    enabled: true
    anchor: dalej
    theme: contrast
    width: content
    spacing: spacious
    heading: 'Dobry układ to dopiero początek.'
    text: 'To demonstracja możliwości Core. Własne treści, obrazy i identyfikację projektu dodamy w kolejnych, osobno zatwierdzonych etapach.'
    links:
      -
        label: 'Jeszcze raz od początku'
        destination_type: url
        url: '#wprowadzenie'
        new_window: false
      -
        label: 'Zobacz istniejący Home'
        destination_type: url
        url: /
        new_window: false
---
