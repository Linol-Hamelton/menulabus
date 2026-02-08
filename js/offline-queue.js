(function () {
  "use strict";

  var KEY = "offline_order_queue_v1";
  var TARGETS = ["/create_new_order.php", "/create_guest_order.php"];

  function isTarget(url) {
    return TARGETS.some(function (path) {
      return url.indexOf(path) !== -1;
    });
  }

  function loadQueue() {
    try {
      var raw = localStorage.getItem(KEY);
      return raw ? JSON.parse(raw) : [];
    } catch (e) {
      return [];
    }
  }

  function saveQueue(queue) {
    localStorage.setItem(KEY, JSON.stringify(queue));
  }

  function enqueue(item) {
    var queue = loadQueue();
    queue.push(item);
    saveQueue(queue);
  }

  async function replayQueue() {
    if (!navigator.onLine) {
      return;
    }

    var queue = loadQueue();
    if (!queue.length) {
      return;
    }

    var nextQueue = [];
    for (var i = 0; i < queue.length; i += 1) {
      var item = queue[i];
      try {
        var response = await fetch(item.url, item.options);
        if (!response.ok) {
          nextQueue.push(item);
        }
      } catch (e) {
        nextQueue.push(item);
      }
    }
    saveQueue(nextQueue);
  }

  var nativeFetch = window.fetch.bind(window);
  window.fetch = async function (input, init) {
    var requestUrl = typeof input === "string" ? input : (input && input.url) || "";
    var requestOptions = init || {};
    var method = (requestOptions.method || "GET").toUpperCase();

    if (method === "POST" && isTarget(requestUrl) && !navigator.onLine) {
      enqueue({
        url: requestUrl,
        options: requestOptions,
        queuedAt: Date.now(),
      });
      return new Response(
        JSON.stringify({
          success: false,
          queued: true,
          message: "Request queued offline and will retry when online",
        }),
        {
          status: 202,
          headers: { "Content-Type": "application/json; charset=utf-8" },
        }
      );
    }

    return nativeFetch(input, init);
  };

  window.addEventListener("online", function () {
    replayQueue();
  });

  if (navigator.onLine) {
    replayQueue();
  }
})();

