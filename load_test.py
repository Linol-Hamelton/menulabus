#!/usr/bin/env python3
"""
Simple load test script for HTTP endpoints.
Uses threading + requests and reuses a Session per worker (keep-alive).
"""

import concurrent.futures
import requests
import time
import statistics
import sys


def fetch(session, url, timeout):
    start = time.perf_counter()
    try:
        response = session.get(url, timeout=timeout)
        elapsed = time.perf_counter() - start
        status = response.status_code
        response.close()
        return elapsed, status
    except requests.exceptions.Timeout:
        elapsed = time.perf_counter() - start
        return elapsed, "timeout"
    except Exception as e:
        elapsed = time.perf_counter() - start
        return elapsed, f"exception: {e}"


def worker(url, timeout, count):
    session = requests.Session()
    times = []
    statuses = []
    for _ in range(count):
        elapsed, status = fetch(session, url, timeout)
        times.append(elapsed)
        statuses.append(status)
    session.close()
    return times, statuses


def run_load_test(url, concurrency, total_requests, timeout=10):
    print(f"Load test {url}")
    print(f"Total requests: {total_requests}, concurrency: {concurrency}, timeout: {timeout}s")

    if total_requests < 1:
        raise ValueError("total_requests must be >= 1")
    if concurrency < 1:
        raise ValueError("concurrency must be >= 1")
    concurrency = min(concurrency, total_requests)

    base = total_requests // concurrency
    remainder = total_requests % concurrency
    per_worker = [base + (1 if i < remainder else 0) for i in range(concurrency)]

    with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as executor:
        futures = []
        start = time.perf_counter()

        for count in per_worker:
            futures.append(executor.submit(worker, url, timeout, count))

        times = []
        statuses = []
        for future in concurrent.futures.as_completed(futures):
            worker_times, worker_statuses = future.result()
            times.extend(worker_times)
            statuses.extend(worker_statuses)

        total_time = time.perf_counter() - start

    successful = sum(1 for s in statuses if isinstance(s, int) and 200 <= s < 300)
    timeouts = sum(1 for s in statuses if s == "timeout")
    errors = len(statuses) - successful - timeouts

    if times:
        avg_time = statistics.mean(times) * 1000
        min_time = min(times) * 1000
        max_time = max(times) * 1000
        std_dev = statistics.stdev(times) * 1000 if len(times) > 1 else 0
        rps = len(times) / total_time
    else:
        avg_time = min_time = max_time = std_dev = rps = 0

    print("\nResults:")
    print(f"  Total time: {total_time:.2f} s")
    print(f"  Successful requests: {successful}")
    print(f"  Timeouts: {timeouts}")
    print(f"  Errors: {errors}")
    print(f"  RPS: {rps:.2f}")
    print(f"  Average response time: {avg_time:.2f} ms")
    print(f"  Min response time: {min_time:.2f} ms")
    print(f"  Max response time: {max_time:.2f} ms")
    print(f"  Std dev: {std_dev:.2f} ms")

    return {
        "total_time": total_time,
        "successful": successful,
        "timeouts": timeouts,
        "errors": errors,
        "rps": rps,
        "avg_response_ms": avg_time,
        "min_response_ms": min_time,
        "max_response_ms": max_time,
        "std_dev_ms": std_dev,
    }


def main():
    import argparse

    parser = argparse.ArgumentParser(description="Simple HTTP load test")
    parser.add_argument("url", help="URL to test, e.g. https://menu.labus.pro/")
    parser.add_argument("-c", "--concurrency", type=int, default=10, help="Parallel workers")
    parser.add_argument("-n", "--requests", type=int, default=100, help="Total requests")
    parser.add_argument("-t", "--timeout", type=float, default=10, help="Request timeout in seconds")

    args = parser.parse_args()

    if not args.url.startswith("http"):
        print("Error: URL must start with http:// or https://")
        sys.exit(1)

    try:
        run_load_test(args.url, args.concurrency, args.requests, args.timeout)
    except KeyboardInterrupt:
        print("\nTest interrupted by user.")
    except Exception as e:
        print(f"Error running test: {e}")
        sys.exit(1)


if __name__ == "__main__":
    main()
