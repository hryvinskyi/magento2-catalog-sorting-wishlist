# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-11-18

### Added
- The sort order **Now in Wishlists** (`wished`): wishlist items per product and store, most-wished first by default.
- The metric `wished`, computed by one aggregate query over the wishlist items and written to the document field
  `sort_wished`.
- Settings per store view (**Enabled**) and at default scope (**Period, Days**, 0 = every addition, by default).
