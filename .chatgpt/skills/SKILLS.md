# Imported Skills

This repo has imported skill guidance for common artifact workflows.

## docx

Use for creating, editing, redlining, and commenting on `.docx` files. The expected workflow is to render the document to page images, inspect the layout visually, fix issues, and repeat until the document is clean before delivering the final DOCX.

## pdfs

Use for PDF reading, inspection, extraction, editing, forms, OCR, redaction, conversion, and diffing. The expected workflow is render → verify → operate → re-render/verify. For text-heavy documents, prefer authoring in DOCX and converting to PDF; for slide-like layouts, prefer authoring in PPTX and exporting to PDF.

## slides

Use for building, editing, and exporting PowerPoint-style presentations and visual aids such as charts, posters, and slide decks. Prefer reusable helpers/templates when appropriate and verify the final deck visually.

## spreadsheets

Use for creating, reading, analyzing, modifying, formatting, and visualizing spreadsheets. Preserve formulas and references when editing existing workbooks, and produce a polished `.xlsx` unless another format is requested.

## Notes for agents

- User, system, and developer instructions always take precedence over these skills.
- Choose the relevant skill based on the artifact type.
- Verify outputs before final delivery whenever the skill requires it.
