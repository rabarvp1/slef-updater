<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-sans);
        }

        .wrap {
            max-width: 480px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .header {
            margin-bottom: 1.5rem;
        }

        .header h1 {
            font-size: 22px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .header p {
            font-size: 14px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .input-row {
            display: flex;
            gap: 8px;
            margin-bottom: 1.5rem;
        }

        .input-row input {
            flex: 1;
            height: 36px;
            border-radius: var(--radius);
            border: 0.5px solid var(--border-strong);
            background: var(--surface-2);
            color: var(--text-primary);
            font-size: 14px;
            padding: 0 12px;
            outline: none;
            font-family: var(--font-sans);
        }

        .input-row input:focus {
            border-color: var(--border-accent);
            box-shadow: 0 0 0 2px var(--bg-accent);
        }

        .input-row input::placeholder {
            color: var(--text-muted);
        }

        .input-row button {
            height: 36px;
            padding: 0 16px;
            border-radius: var(--radius);
            border: none;
            background: var(--fill-accent);
            color: var(--on-accent);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: var(--font-sans);
        }

        .input-row button:hover {
            background: var(--fill-accent-hover);
        }

        .input-row button:active {
            transform: scale(0.98);
        }

        .filters {
            display: flex;
            gap: 4px;
            margin-bottom: 1rem;
        }

        .filters button {
            height: 28px;
            padding: 0 12px;
            border-radius: var(--radius);
            border: 0.5px solid var(--border);
            background: transparent;
            color: var(--text-secondary);
            font-size: 13px;
            cursor: pointer;
            font-family: var(--font-sans);
            transition: all 0.1s;
        }

        .filters button.active {
            background: var(--surface-1);
            border-color: var(--border-strong);
            color: var(--text-primary);
            font-weight: 500;
        }

        .filters button:hover:not(.active) {
            background: var(--surface-1);
        }

        .todo-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .todo-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            background: var(--surface-2);
            border: 0.5px solid var(--border);
            border-radius: var(--radius);
            transition: border-color 0.1s;
        }

        .todo-item:hover {
            border-color: var(--border-strong);
        }

        .todo-item.done .todo-text {
            text-decoration: line-through;
            color: var(--text-muted);
        }

        .check-btn {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 0.5px solid var(--border-strong);
            background: transparent;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.1s;
        }

        .check-btn.checked {
            background: var(--fill-success);
            border-color: var(--fill-success);
        }

        .check-btn.checked i {
            color: var(--on-success);
        }

        .check-btn i {
            font-size: 12px;
            color: var(--text-muted);
        }

        .check-btn:not(.checked) i {
            opacity: 0;
        }

        .check-btn:hover i {
            opacity: 1;
        }

        .todo-text {
            flex: 1;
            font-size: 14px;
            color: var(--text-primary);
        }

        .del-btn {
            width: 28px;
            height: 28px;
            border-radius: var(--radius);
            border: none;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.1s;
        }

        .del-btn:hover {
            background: var(--bg-danger);
            color: var(--text-danger);
        }

        .todo-item:hover .del-btn {
            opacity: 1;
        }

        .empty {
            text-align: center;
            padding: 2.5rem 0;
            color: var(--text-muted);
            font-size: 14px;
        }

        .empty i {
            font-size: 32px;
            display: block;
            margin-bottom: 8px;
            color: var(--border-strong);
        }

        .footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 0.5px solid var(--border);
        }

        .footer span {
            font-size: 13px;
            color: var(--text-muted);
        }

        .clear-btn {
            font-size: 13px;
            color: var(--text-danger);
            background: none;
            border: none;
            cursor: pointer;
            font-family: var(--font-sans);
        }

        .clear-btn:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <h2 class="sr-only">Todo list app — add, complete, and filter tasks</h2>

    <div class="wrap">
        <div class="header">
            <h1>My tasks</h1>
            <p id="subtitle">0 tasks remaining</p>
        </div>

        <div class="input-row">
            <input type="text" id="new-task" placeholder="Add a new task..." />
            <button onclick="addTask()">
                <i class="ti ti-plus" aria-hidden="true"></i>
                Add
            </button>
        </div>

        <div class="filters">
            <button class="active" onclick="setFilter('all', this)">All</button>
            <button onclick="setFilter('active', this)">Active</button>
            <button onclick="setFilter('done', this)">Done</button>
        </div>

        <div class="todo-list" id="list"></div>

        <div class="footer">
            <span id="count-label"></span>
            <button class="clear-btn" onclick="clearDone()">Clear done</button>
        </div>
    </div>

    <script>
        let tasks = [{
                id: 1,
                text: 'Review invoice logic',
                done: true
            },
            {
                id: 2,
                text: 'Fix duplicate fixer command',
                done: false
            },
            {
                id: 3,
                text: 'Push Git branch to remote',
                done: false
            },
        ];
        let filter = 'all';
        let nextId = 4;

        function render() {
            const list = document.getElementById('list');
            const filtered = tasks.filter(t =>
                filter === 'all' ? true : filter === 'done' ? t.done : !t.done
            );

            document.getElementById('subtitle').textContent =
                tasks.filter(t => !t.done).length + ' tasks remaining';

            const done = tasks.filter(t => t.done).length;
            document.getElementById('count-label').textContent =
                done + ' of ' + tasks.length + ' done';

            if (!filtered.length) {
                list.innerHTML = `<div class="empty"><i class="ti ti-checks" aria-hidden="true"></i>${
      filter === 'done' ? 'No completed tasks yet' :
      filter === 'active' ? 'All done! Great work.' :
      'Add your first task above'
    }</div>`;
                return;
            }

            list.innerHTML = filtered.map(t => `
    <div class="todo-item ${t.done ? 'done' : ''}" id="item-${t.id}">
      <button class="check-btn ${t.done ? 'checked' : ''}" onclick="toggle(${t.id})" aria-label="${t.done ? 'Mark incomplete' : 'Mark complete'}">
        <i class="ti ti-check" aria-hidden="true"></i>
      </button>
      <span class="todo-text">${t.text}</span>
      <button class="del-btn" onclick="remove(${t.id})" aria-label="Delete task">
        <i class="ti ti-trash" style="font-size:14px" aria-hidden="true"></i>
      </button>
    </div>
  `).join('');
        }

        function addTask() {
            const input = document.getElementById('new-task');
            const text = input.value.trim();
            if (!text) return;
            tasks.push({
                id: nextId++,
                text,
                done: false
            });
            input.value = '';
            render();
        }

        function toggle(id) {
            tasks = tasks.map(t => t.id === id ? {
                ...t,
                done: !t.done
            } : t);
            render();
        }

        function remove(id) {
            tasks = tasks.filter(t => t.id !== id);
            render();
        }

        function setFilter(f, btn) {
            filter = f;
            document.querySelectorAll('.filters button').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            render();
        }

        function clearDone() {
            tasks = tasks.filter(t => !t.done);
            render();
        }

        document.getElementById('new-task').addEventListener('keydown', e => {
            if (e.key === 'Enter') addTask();
        });

        render();
    </script>
</body>

</html>
