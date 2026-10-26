/**
 * ==============================================================================
 * NODE.JS ADVERSARIAL STRESS TEST FOR DATAGRID & AVATAR STACK JAVASCRIPT LOGIC
 * ==============================================================================
 */

const assert = require('assert');

console.log('========================================================================');
console.log(' JAVASCRIPT EMPIRICAL VERIFICATION — DATAGRID & AVATAR STACK');
console.log('========================================================================\n');

let passCount = 0;
let totalCount = 0;

function runTest(name, fn) {
    totalCount++;
    try {
        fn();
        passCount++;
        console.log(`  \x1b[32m[PASS]\x1b[0m ${name}`);
    } catch (err) {
        console.error(`  \x1b[31m[FAIL]\x1b[0m ${name}: ${err.message}`);
    }
}

// -----------------------------------------------------------------------------
// 1. DataGrid Pagination & Filtering Simulation
// -----------------------------------------------------------------------------
function createMockDataGrid(volunteersList) {
    let coordinatorData = {
        volunteers: volunteersList || []
    };
    let dataGridPage = 1;
    let dataGridPageSize = 10;

    let searchVal = '';
    let unitVal = '';
    let skillVal = '';
    let shiftsVal = '';

    function renderDataGrid() {
        const q = (searchVal || '').toLowerCase().trim();
        const unit = unitVal || '';
        const skill = skillVal || '';
        const shiftStatus = shiftsVal || '';

        let list = (coordinatorData.volunteers || []).filter(v => {
            if (q) {
                const matchQ = (v.full_name || '').toLowerCase().includes(q) ||
                               (v.student_code || '').toLowerCase().includes(q) ||
                               (v.email || '').toLowerCase().includes(q) ||
                               (v.phone || '').toLowerCase().includes(q);
                if (!matchQ) return false;
            }
            if (unit && !(v.chapter_name || '').includes(unit)) {
                return false;
            }
            if (skill && !(v.skills || []).some(s => s.code === skill)) {
                return false;
            }
            if (shiftStatus) {
                const count = v.assigned_count || 0;
                if (shiftStatus === 'ZERO' && count !== 0) return false;
                if ($shiftStatus === 'ONE' && count !== 1) return false;
                if (shiftStatus === 'TWO' && count !== 2) return false;
                if (shiftStatus === 'THREE_PLUS' && count < 3) return false;
            }
            return true;
        });

        const total = list.length;
        const totalPages = Math.max(1, Math.ceil(total / dataGridPageSize));
        if (dataGridPage > totalPages) dataGridPage = totalPages;
        if (dataGridPage < 1) dataGridPage = 1;

        const startIdx = (dataGridPage - 1) * dataGridPageSize;
        const endIdx = Math.min(total, startIdx + dataGridPageSize);
        const paged = list.slice(startIdx, endIdx);

        const infoText = total > 0
            ? `Hiển thị ${startIdx + 1} – ${endIdx} trong tổng số ${total} tình nguyện viên`
            : 'Không tìm thấy tình nguyện viên phù hợp với tiêu chí lọc.';

        const prevDisabled = (dataGridPage <= 1);
        const nextDisabled = (dataGridPage >= totalPages);

        return {
            total,
            totalPages,
            currentPage: dataGridPage,
            startIdx,
            endIdx,
            pagedCount: paged.length,
            paged,
            infoText,
            prevDisabled,
            nextDisabled,
            isEmpty: paged.length === 0
        };
    }

    return {
        render: renderDataGrid,
        setPage: (p) => { dataGridPage = p; return renderDataGrid(); },
        setPageSize: (size) => {
            dataGridPageSize = parseInt(size, 10) || 10;
            dataGridPage = 1;
            return renderDataGrid();
        },
        setSearch: (s) => { searchVal = s; dataGridPage = 1; return renderDataGrid(); },
        setUnit: (u) => { unitVal = u; dataGridPage = 1; return renderDataGrid(); },
        setSkill: (sk) => { skillVal = sk; dataGridPage = 1; return renderDataGrid(); },
    };
}

// Generate 45 mock volunteers
const mockVolunteers = [];
for (let i = 1; i <= 45; i++) {
    mockVolunteers.push({
        id: i,
        full_name: `Tình nguyện viên ${i}`,
        student_code: `SV${String(i).padStart(3, '0')}`,
        email: `sv${i}@example.com`,
        phone: `09000000${String(i).padStart(2, '0')}`,
        chapter_name: (i % 2 === 0) ? 'Đoàn Khoa CNTT' : 'Đoàn Khoa Y',
        assigned_count: i % 4,
        skills: [{ code: (i % 2 === 0 ? 'LOGISTICS' : 'FIRST_AID'), level: 3 }]
    });
}

runTest('DataGrid: Empty Pool Boundary (0 volunteers)', () => {
    const dg = createMockDataGrid([]);
    const state = dg.render();
    assert.strictEqual(state.total, 0);
    assert.strictEqual(state.totalPages, 1);
    assert.strictEqual(state.currentPage, 1);
    assert.strictEqual(state.pagedCount, 0);
    assert.strictEqual(state.isEmpty, true);
    assert.strictEqual(state.prevDisabled, true);
    assert.strictEqual(state.nextDisabled, true);
    assert.ok(state.infoText.includes('Không tìm thấy tình nguyện viên'));
});

runTest('DataGrid: Filter matching 0 candidates', () => {
    const dg = createMockDataGrid(mockVolunteers);
    dg.setSearch('nguoi_khong_ton_tai_12345');
    const state = dg.render();
    assert.strictEqual(state.total, 0);
    assert.strictEqual(state.totalPages, 1);
    assert.strictEqual(state.currentPage, 1);
    assert.strictEqual(state.pagedCount, 0);
    assert.strictEqual(state.isEmpty, true);
    assert.strictEqual(state.prevDisabled, true);
    assert.strictEqual(state.nextDisabled, true);
});

runTest('DataGrid: Switching Page Size (10 -> 20 -> 50)', () => {
    const dg = createMockDataGrid(mockVolunteers);

    // Initial size 10
    let state = dg.render();
    assert.strictEqual(state.totalPages, 5);
    assert.strictEqual(state.pagedCount, 10);
    assert.strictEqual(state.prevDisabled, true);
    assert.strictEqual(state.nextDisabled, false);

    // Navigate to page 4
    state = dg.setPage(4);
    assert.strictEqual(state.currentPage, 4);
    assert.strictEqual(state.prevDisabled, false);
    assert.strictEqual(state.nextDisabled, false);

    // Switch to page size 20: page must reset to 1
    state = dg.setPageSize(20);
    assert.strictEqual(state.currentPage, 1);
    assert.strictEqual(state.totalPages, 3);
    assert.strictEqual(state.pagedCount, 20);

    // Switch to page size 50: page must reset to 1
    state = dg.setPageSize(50);
    assert.strictEqual(state.currentPage, 1);
    assert.strictEqual(state.totalPages, 1);
    assert.strictEqual(state.pagedCount, 45);
    assert.strictEqual(state.prevDisabled, true);
    assert.strictEqual(state.nextDisabled, true);
});

runTest('DataGrid: Out-of-bounds Page Clamping', () => {
    const dg = createMockDataGrid(mockVolunteers);

    // Underflow: page -10 -> clamped to 1
    let state = dg.setPage(-10);
    assert.strictEqual(state.currentPage, 1);

    // Underflow: page 0 -> clamped to 1
    state = dg.setPage(0);
    assert.strictEqual(state.currentPage, 1);

    // Overflow: page 9999 -> clamped to totalPages (5)
    state = dg.setPage(9999);
    assert.strictEqual(state.currentPage, 5);
    assert.strictEqual(state.nextDisabled, true);
    assert.strictEqual(state.prevDisabled, false);
});

// -----------------------------------------------------------------------------
// 2. Avatar Stack Capping Simulation
// -----------------------------------------------------------------------------
function simulateAvatarStack(assignedVolunteers) {
    const list = assignedVolunteers || [];
    if (list.length === 0) {
        return {
            emptyMessage: 'Chưa có nhân sự',
            avatars: [],
            extraBadge: null,
            totalElements: 1 // 1 span message
        };
    }

    const maxShowAvatars = 4;
    const shownAvatars = list.slice(0, maxShowAvatars);
    const extraCount = list.length - maxShowAvatars;

    const extraBadge = extraCount > 0 ? { count: extraCount, label: `+${extraCount}` } : null;

    return {
        emptyMessage: null,
        avatars: shownAvatars,
        extraBadge,
        totalElements: shownAvatars.length + (extraBadge ? 1 : 0)
    };
}

runTest('Avatar Stack: 0 assigned volunteers (Empty state)', () => {
    const res = simulateAvatarStack([]);
    assert.strictEqual(res.emptyMessage, 'Chưa có nhân sự');
    assert.strictEqual(res.avatars.length, 0);
    assert.strictEqual(res.extraBadge, null);
});

runTest('Avatar Stack: 1 assigned volunteer', () => {
    const res = simulateAvatarStack([{ id: 1, name: 'TNV 1' }]);
    assert.strictEqual(res.emptyMessage, null);
    assert.strictEqual(res.avatars.length, 1);
    assert.strictEqual(res.extraBadge, null);
    assert.strictEqual(res.totalElements, 1);
});

runTest('Avatar Stack: 4 assigned volunteers (At threshold)', () => {
    const v4 = [1, 2, 3, 4].map(id => ({ id, name: `TNV ${id}` }));
    const res = simulateAvatarStack(v4);
    assert.strictEqual(res.avatars.length, 4);
    assert.strictEqual(res.extraBadge, null);
    assert.strictEqual(res.totalElements, 4);
});

runTest('Avatar Stack: 10 assigned volunteers (Capped at 4 + +6 badge)', () => {
    const v10 = Array.from({ length: 10 }, (_, i) => ({ id: i + 1, name: `TNV ${i + 1}` }));
    const res = simulateAvatarStack(v10);
    assert.strictEqual(res.avatars.length, 4, 'Must strictly cap at 4 avatars');
    assert.notStrictEqual(res.extraBadge, null);
    assert.strictEqual(res.extraBadge.count, 6);
    assert.strictEqual(res.extraBadge.label, '+6');
    assert.strictEqual(res.totalElements, 5, 'Total rendered elements must be 5 (4 avatars + 1 badge)');
});

runTest('Avatar Stack: 100 assigned volunteers (Extreme stress)', () => {
    const v100 = Array.from({ length: 100 }, (_, i) => ({ id: i + 1, name: `TNV ${i + 1}` }));
    const res = simulateAvatarStack(v100);
    assert.strictEqual(res.avatars.length, 4, 'Must never exceed 4 avatars');
    assert.strictEqual(res.extraBadge.count, 96);
    assert.strictEqual(res.extraBadge.label, '+96');
    assert.strictEqual(res.totalElements, 5, 'Must never expand beyond 5 elements');
});

console.log('\n------------------------------------------------------------------------');
console.log(` SUMMARY: ${passCount}/${totalCount} tests passed`);
console.log('------------------------------------------------------------------------\n');

if (passCount === totalCount) {
    console.log('✔ ALL JAVASCRIPT DATAGRID & AVATAR STACK EMPIRICAL TESTS PASSED.');
    process.exit(0);
} else {
    process.exit(1);
}
