-- Tips migration: add tips column to orders table
-- Run once on production DB
ALTER TABLE orders
    ADD COLUMN `tips` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `total`;
